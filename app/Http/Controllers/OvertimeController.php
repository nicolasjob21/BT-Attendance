<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\OvertimeRequest;
use App\Support\WorkHours;
use App\Support\WorkSessions;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class OvertimeController extends Controller
{
    public function index(Request $request)
    {
        $employee = $request->user()->employee;
        $canApprove = $request->user()->can('approve requests');

        $query = OvertimeRequest::with(['employee', 'approver'])->latest();
        if (! $canApprove && $employee) {
            $query->where('employee_id', $employee->id);
        }

        $requests = $query->paginate(20);

        return view('overtime.index', compact('requests', 'canApprove'));
    }

    public function create(Request $request)
    {
        $multipliers = OvertimeRequest::MULTIPLIERS;

        return view('overtime.create', compact('multipliers'));
    }

    public function store(Request $request)
    {
        $employee = $request->user()->employee;
        abort_unless($employee, 403);

        // Hours and OT type are never entered by hand — both are derived from
        // the attendance record and the date (weekday vs weekend).
        $data = $request->validate([
            'ot_date' => ['required', 'date'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $calc = $this->computeOvertime($employee, $data['ot_date']);

        if (! $calc['ok']) {
            return back()->withInput()->withErrors(['ot_date' => $calc['message']]);
        }

        $employee->overtimeRequests()->create([
            'ot_date' => $data['ot_date'],
            'hours' => $calc['hours'],
            'ot_type' => $calc['ot_type'],
            'reason' => $data['reason'] ?? null,
            'status' => 'pending',
        ]);

        return redirect()->route('overtime.index')
            ->with('status', "Overtime request for {$calc['hours']}h submitted for approval.");
    }

    /**
     * Live preview of the auto-calculated overtime hours for a chosen date,
     * used by the file-overtime form (returns JSON).
     */
    public function preview(Request $request)
    {
        $employee = $request->user()->employee;
        abort_unless($employee, 403);

        $date = $request->validate(['date' => ['required', 'date']])['date'];
        $calc = $this->computeOvertime($employee, $date);

        return response()->json([
            'ok' => $calc['ok'],
            'hours' => $calc['hours'],
            'ot_type' => $calc['ot_type'],
            'ot_type_label' => OvertimeRequest::TYPE_LABELS[$calc['ot_type']] ?? $calc['ot_type'],
            'message' => $calc['message'],
            'actual_out' => $calc['actual_out']?->format('g:i A'),
            'scheduled_out' => $calc['scheduled_out']?->format('g:i A'),
        ]);
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

        return back()->with('status', 'Overtime request approved.');
    }

    public function deny(Request $request, OvertimeRequest $overtime)
    {
        $this->decide($request, $overtime, 'denied');

        return back()->with('status', 'Overtime request denied.');
    }

    private function decide(Request $request, OvertimeRequest $overtime, string $status): void
    {
        $overtime->update([
            'status' => $status,
            'approved_by' => $request->user()->employee?->id,
            'approved_at' => now(),
        ]);
    }
}
