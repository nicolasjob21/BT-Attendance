<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\OvertimeRequest;
use App\Models\User;
use App\Notifications\ApprovalRequested;
use App\Notifications\RequestReviewed;
use App\Support\WorkHours;
use App\Support\WorkSessions;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

class OvertimeController extends Controller
{
    /**
     * How many days back an OT request may still be filed. Three covers a
     * Saturday/Sunday site deployment filed on the Monday after.
     */
    private const LATE_FILING_DAYS = 3;

    public function index(Request $request)
    {
        $employee = $request->user()->employee;
        $canApprove = $request->user()->can('approve requests');

        $query = OvertimeRequest::with(['employee.schedule', 'approver'])->latest('ot_date')->latest('id');
        if (! $canApprove && $employee) {
            $query->where('employee_id', $employee->id);
        }

        $requests = $query->paginate(20);

        // Approved requests whose day has passed get their actual hours
        // derived from attendance the first time anyone looks at them.
        $requests->getCollection()->each(fn (OvertimeRequest $r) => $this->syncActualHours($r));

        return view('overtime.index', compact('requests', 'canApprove'));
    }

    /**
     * Derive the actual overtime hours for an approved request from the
     * employee's attendance on that date. Left null until there's a clock-out.
     */
    private function syncActualHours(OvertimeRequest $ot): void
    {
        if (! $ot->awaitingActualHours() || ! $ot->employee) {
            return;
        }

        $calc = $this->computeOvertime($ot->employee, $ot->ot_date->toDateString());
        if (! $calc['actual_out']) {
            return; // not clocked out yet
        }

        $ot->update([
            'hours' => $calc['hours'],
            'ot_type' => $calc['ot_type'],
            'hours_synced_at' => now(),
        ]);
    }

    public function create(Request $request)
    {
        $employee = $request->user()->employee;
        abort_unless($employee, 403);

        // Default the planned start to the scheduled clock-out (or 6 PM for flexible staff).
        $defaultStart = $employee->schedule?->time_out
            ? Carbon::parse($employee->schedule->time_out)->format('H:i')
            : '18:00';

        return view('overtime.create', [
            'defaultStart' => $defaultStart,
            // Weekend site work is a full shift, not "stay late" — the form
            // swaps to these defaults when a Saturday/Sunday is picked.
            'restDayStart' => '08:00',
            'restDayEnd' => '17:00',
            'minDate' => Carbon::today()->subDays(self::LATE_FILING_DAYS)->toDateString(),
        ]);
    }

    /**
     * File an overtime request BEFORE the OT is worked: the date, the planned
     * window and what the overtime is for. Admin/HR approves it; the actual
     * hours are derived from attendance afterwards, never typed in.
     */
    public function store(Request $request)
    {
        $employee = $request->user()->employee;
        abort_unless($employee, 403);

        $data = $request->validate([
            'ot_date' => ['required', 'date', 'after_or_equal:' . Carbon::today()->subDays(self::LATE_FILING_DAYS)->toDateString()],
            'planned_start' => ['required', 'date_format:H:i'],
            'planned_end' => ['required', 'date_format:H:i'],
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ], [
            'ot_date.after_or_equal' => 'Overtime must be requested in advance — pick today or a future date (up to ' . self::LATE_FILING_DAYS . ' days back is allowed for late filing, e.g. weekend site work filed on Monday).',
            'reason.required' => 'Tell your approver what the overtime is for (the task or work to be done).',
            'reason.min' => 'Please describe the work in a bit more detail.',
        ]);

        $requestedHours = $this->plannedHours($data['planned_start'], $data['planned_end']);
        if ($requestedHours <= 0 || $requestedHours > 12) {
            return back()->withInput()->withErrors(['planned_end' => 'The planned window must be between 15 minutes and 12 hours.']);
        }

        $duplicate = $employee->overtimeRequests()
            ->whereDate('ot_date', $data['ot_date'])
            ->whereIn('status', ['pending', 'approved'])
            ->exists();
        if ($duplicate) {
            return back()->withInput()->withErrors(['ot_date' => 'You already have a pending or approved overtime request for that date.']);
        }

        $isWeekend = Carbon::parse($data['ot_date'])->isWeekend();

        $ot = $employee->overtimeRequests()->create([
            'ot_date' => $data['ot_date'],
            'planned_start' => $data['planned_start'],
            'planned_end' => $data['planned_end'],
            'requested_hours' => $requestedHours,
            'hours' => null,
            'ot_type' => $isWeekend ? 'rest_day' : 'regular',
            'reason' => $data['reason'],
            'status' => 'pending',
        ]);

        // If they're filing after already clocking out (same-day / late filing),
        // the actual hours can be derived right away for the approver to see.
        // (Only approved requests sync, so this simply stays null until then.)

        Notification::send(
            User::permission('approve requests')->get(),
            new ApprovalRequested(
                'Overtime',
                $employee->full_name,
                "{$requestedHours}h on " . $ot->ot_date->format('M j') . ' (' . $ot->plannedWindow() . ') — ' . Str::limit($data['reason'], 80),
                route('overtime.index'),
            ),
        );

        return redirect()->route('overtime.index')
            ->with('status', "Overtime request for {$requestedHours}h on " . $ot->ot_date->format('M j') . ' sent for approval.');
    }

    /** Hours between two HH:MM times, allowing a window that crosses midnight. */
    private function plannedHours(string $start, string $end): float
    {
        $s = Carbon::createFromFormat('H:i', $start);
        $e = Carbon::createFromFormat('H:i', $end);
        if ($e->lte($s)) {
            $e->addDay();
        }

        return round($s->diffInMinutes($e) / 60, 2);
    }

    /**
     * Overtime hours = actual time-out − scheduled time-out.
     *
     * For staff with a fixed schedule, OT is the time worked past their
     * scheduled clock-out. For flexible staff (no scheduled out) or weekend
     * work, OT falls back to time worked beyond the standard 8-hour day
     * (all of it, on a rest day). Hours are always derived from attendance,
     * never typed in.
     *
     * @return array{ok: bool, hours: float, message: string, actual_out: ?Carbon, scheduled_out: ?Carbon}
     */
    private function computeOvertime(Employee $employee, string $date): array
    {
        $sessions = WorkSessions::startingOn(
            WorkSessions::pair(
                $employee->attendanceLogs()
                    ->whereBetween('logged_at', [
                        Carbon::parse($date)->startOfDay(),
                        Carbon::parse($date)->addDay()->endOfDay(),
                    ])
                    ->orderBy('logged_at')
                    ->get()
            ),
            $date
        );

        $isWeekend = Carbon::parse($date)->isWeekend();

        // Working week is Mon–Fri. Weekend work is rest-day OT (130%); a weekday
        // is regular OT (125%). The type is derived from the date, never chosen.
        $otType = $isWeekend ? 'rest_day' : 'regular';

        $closingOut = WorkSessions::closingOut($sessions);
        $actualOut = $closingOut?->logged_at;

        if (! $actualOut) {
            return [
                'ok' => false, 'hours' => 0.0, 'ot_type' => $otType, 'actual_out' => null, 'scheduled_out' => null,
                'message' => 'No clock-out found for that date. Clock out first, then file your overtime.',
            ];
        }

        $scheduledTimeOut = $employee->schedule?->time_out; // e.g. "17:30:00" or null

        // Fixed-schedule weekday: OT is everything past the scheduled clock-out.
        if ($scheduledTimeOut && ! $isWeekend) {
            $scheduledOut = Carbon::parse($date . ' ' . $scheduledTimeOut);
            $minutes = max(0, (int) round($scheduledOut->diffInMinutes($actualOut)));
            $hours = round($minutes / 60, 2);

            $message = $hours > 0
                ? "Auto-calculated {$hours}h — actual out {$actualOut->format('g:i A')} minus scheduled out {$scheduledOut->format('g:i A')}."
                : "Your time out ({$actualOut->format('g:i A')}) is not past your scheduled out ({$scheduledOut->format('g:i A')}), so there is no overtime.";

            return [
                'ok' => $hours > 0, 'hours' => $hours, 'ot_type' => $otType,
                'actual_out' => $actualOut, 'scheduled_out' => $scheduledOut, 'message' => $message,
            ];
        }

        // Flexible or weekend: OT from worked time beyond the standard 8h day.
        $minutes = WorkSessions::workedMinutes($sessions);
        $hours = round(WorkHours::split($minutes, $isWeekend)['overtime'] / 60, 2);
        $basis = $isWeekend ? 'weekend rest-day work (all hours are overtime)' : 'time worked beyond 8 hours';

        return [
            'ok' => $hours > 0, 'hours' => $hours, 'ot_type' => $otType,
            'actual_out' => $actualOut, 'scheduled_out' => null,
            'message' => $hours > 0
                ? "Auto-calculated {$hours}h from {$basis} — actual out {$actualOut->format('g:i A')}."
                : "No overtime — {$basis}.",
        ];
    }

    public function approve(Request $request, OvertimeRequest $overtime)
    {
        $this->decide($request, $overtime, 'approved');

        // Late filing: if the day is already worked, fill in the actual hours now.
        $this->syncActualHours($overtime->fresh());

        return back()->with('status', 'Overtime request approved.');
    }

    public function deny(Request $request, OvertimeRequest $overtime)
    {
        $this->decide($request, $overtime, 'denied');

        return back()->with('status', 'Overtime request denied.');
    }

    private function decide(Request $request, OvertimeRequest $overtime, string $status): void
    {
        abort_unless($overtime->status === 'pending', 422, 'This request has already been decided.');

        $data = $request->validate([
            'remarks' => ['nullable', 'string', 'max:500'],
        ]);

        $overtime->update([
            'status' => $status,
            'admin_remarks' => $data['remarks'] ?? null,
            'approved_by' => $request->user()->employee?->id,
            'approved_at' => now(),
        ]);

        $overtime->employee->user?->notify(new RequestReviewed('Overtime', $status, route('overtime.index')));
    }
}
