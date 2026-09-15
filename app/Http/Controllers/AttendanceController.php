<?php

namespace App\Http\Controllers;

use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\Site;
use App\Models\User;
use App\Notifications\LocationExceptionFlagged;
use App\Notifications\RequestReviewed;
use App\Services\AttendanceSoftCopy;
use App\Services\GeofenceService;
use App\Support\DataUrlPhoto;
use App\Support\WorkHours;
use App\Support\WorkSessions;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AttendanceController extends Controller
{
    /** Clock in/out capture screen. */
    public function create(Request $request, GeofenceService $geofence)
    {
        $employee = $request->user()->employee;
        abort_unless($employee, 403, 'No employee profile linked to this account.');

        $lastLog = $employee->attendanceLogs()->latest('logged_at')->first();
        $nextAction = ($lastLog && $lastLog->log_type === 'time_in') ? 'time_out' : 'time_in';

        // Every ACTIVE authorized location (main office + live project sites +
        // temporary venues) is drawn on the map. The employee may punch at any
        // of them; the assigned project is only highlighted.
        $sites = Site::query()->activeOn()
            ->orderByRaw("type = 'office' desc")->orderBy('name')
            ->get(['id', 'name', 'type', 'address', 'latitude', 'longitude', 'geofence_radius_m']);

        $assignedSite = $employee->assignedSite();

        return view('attendance.create', [
            'lastLog' => $lastLog,
            'nextAction' => $nextAction,
            'employeeName' => $employee->full_name,
            'sites' => $sites,
            'assignedSiteId' => $assignedSite?->id,
            'assignedSiteName' => $assignedSite?->name,
            'geofenceMode' => $geofence->mode(),
            'minAccuracy' => $geofence->minAccuracyMeters(),
        ]);
    }

    /** Persist a clock event with the captured GPS location. */
    public function store(Request $request, GeofenceService $geofence)
    {
        $employee = $request->user()->employee;
        abort_unless($employee, 403);

        $data = $request->validate([
            'log_type' => ['required', 'in:time_in,time_out'],
            // Coordinates are nullable so a GPS-denied device can still submit;
            // the punch is then recorded as "gps_unavailable" for HR review
            // (or rejected outright in strict mode).
            'latitude' => ['nullable', 'required_with:longitude', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'required_with:latitude', 'numeric', 'between:-180,180'],
            'gps_accuracy' => ['nullable', 'numeric', 'min:0'],
            'location_reason' => ['nullable', 'string', 'max:500'],
            'photo' => ['required', 'string'], // base64 data URL from the camera
            'synced_offline' => ['sometimes', 'boolean'],
        ]);

        $lat = isset($data['latitude']) ? (float) $data['latitude'] : null;
        $lng = isset($data['longitude']) ? (float) $data['longitude'] : null;
        $accuracy = isset($data['gps_accuracy']) ? (float) $data['gps_accuracy'] : null;

        // Server-side geofence check against every active location. The
        // coordinates are validated here regardless of what the client showed,
        // so a spoofed or hand-placed pin can still be caught.
        $result = $geofence->evaluate($employee, $lat, $lng, $accuracy);

        if ($geofence->blocks($result)) {
            return back()->withErrors([
                'latitude' => $result->message.' You must be inside an authorized work site to '
                    .($data['log_type'] === 'time_in' ? 'clock in.' : 'clock out.'),
            ]);
        }

        // An out-of-area punch needs the employee's own explanation so HR has
        // something to review; nothing to explain when the fix is verified.
        $reason = $result->isException() ? trim((string) ($data['location_reason'] ?? '')) : '';
        if ($result->isException() && $reason === '') {
            return back()->withErrors([
                'location_reason' => 'Please tell HR why you are clocking '.($data['log_type'] === 'time_in' ? 'in' : 'out')
                    .' outside the authorized area (e.g. approved temporary location, GPS inaccurate).',
            ])->withInput($request->except('photo'));
        }

        $photoPath = $this->storePhoto($data['photo'], $employee->id);

        $log = AttendanceLog::create([
            'employee_id' => $employee->id,
            'log_type' => $data['log_type'],
            'logged_at' => Carbon::now(),
            'latitude' => $lat,
            'longitude' => $lng,
            'photo_path' => $photoPath,
            'synced_offline' => (bool) ($data['synced_offline'] ?? false),
            'location_reason' => $reason !== '' ? $reason : null,
            'location_verification_status' => $geofence->initialVerificationStatus($result),
        ] + $result->toLogAttributes());

        $verb = $data['log_type'] === 'time_in' ? 'Clocked in' : 'Clocked out';
        $message = "{$verb} at ".Carbon::now()->format('g:i A').'.';

        if ($result->status === GeofenceService::AUTHORIZED_ALTERNATE_LOCATION) {
            $message .= " Recorded at {$result->site->name} (your assigned project is {$result->assignedSite->name}).";
        } elseif ($result->isException()) {
            $message .= $log->location_verification_status === 'pending'
                ? ' Your location could not be verified — this punch is pending HR approval.'
                : ' Your location could not be verified — HR has been notified to review it.';
            $this->notifyReviewers($log);
        }

        // Early in is the employee's own choice and does not affect pay — just
        // let them know it was recorded as an early clock-in.
        if ($data['log_type'] === 'time_in'
            && app(AttendanceSoftCopy::class)->status($log->loadMissing('employee.schedule')) === 'Early In') {
            $message .= ' You clocked in early — that\'s fine, it does not affect your pay.';
        }

        // Flash the new punch so the history screen can offer an immediate download.
        return redirect()->route('attendance.index')
            ->with('status', $message)
            ->with('softcopy_log_id', $log->id)
            ->with('softcopy_type', $data['log_type'] === 'time_in' ? 'in' : 'out');
    }

    /**
     * Downloadable proof-of-attendance soft copy (PNG) for one punch.
     * GET /attendance/{id}/softcopy/{type} where {type} is "in" or "out".
     */
    public function softCopy(Request $request, int $id, string $type)
    {
        abort_unless(in_array($type, ['in', 'out'], true), 404);

        $log = AttendanceLog::with('employee.schedule')->findOrFail($id);

        // The URL {type} must match the punch, so ATT-{id}-IN can't be faked as OUT.
        abort_unless($log->log_type === ($type === 'in' ? 'time_in' : 'time_out'), 404);

        // Employees may download their own; HR/CEO may download anyone's.
        $user = $request->user();
        abort_unless(
            $log->employee_id === $user->employee?->id || $user->can('view team reports'),
            403,
        );

        $soft = app(AttendanceSoftCopy::class);

        return response($soft->png($log), 200, [
            'Content-Type' => 'image/png',
            'Content-Disposition' => 'attachment; filename="'.$soft->filename($log).'"',
        ]);
    }

    /** Personal attendance history. */
    public function index(Request $request)
    {
        $employee = $request->user()->employee;
        abort_unless($employee, 403);

        $logs = $employee->attendanceLogs()
            ->with(['site', 'assignedSite', 'locationVerifier:id,name'])
            ->latest('logged_at')
            ->paginate(20);

        return view('attendance.index', compact('logs'));
    }

    /** HR/supervisor monitor: every active employee's time in/out for a chosen date. */
    public function monitor(Request $request)
    {
        $date = $request->filled('date')
            ? Carbon::parse($request->input('date'))->toDateString()
            : Carbon::today()->toDateString();

        $search = trim((string) $request->input('search', ''));

        // Working week is Mon–Fri. Work that lands on a weekend is rest-day
        // overtime, so every worked minute that day counts as overtime.
        $isRestDay = Carbon::parse($date)->isWeekend();

        $employees = Employee::query()
            ->where('status', 'active')
            ->when($search !== '', function ($q) use ($search) {
                $q->where(fn ($w) => $w
                    ->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('employee_no', 'like', "%{$search}%"));
            })
            ->orderBy('first_name')->orderBy('last_name')
            ->get();

        // Fetch a window from the selected day's start through the *next* day, so an
        // overnight (graveyard) shift's time-out that lands after midnight can still be
        // paired with the time-in that opened it.
        $windowStart = Carbon::parse($date)->startOfDay();
        $windowEnd = Carbon::parse($date)->addDay()->endOfDay();

        $logsByEmployee = AttendanceLog::whereBetween('logged_at', [$windowStart, $windowEnd])
            ->whereIn('employee_id', $employees->pluck('id'))
            ->with(['otVerifier:id,name', 'site:id,name', 'assignedSite:id,name', 'locationVerifier:id,name'])
            ->orderBy('logged_at')
            ->get()
            ->groupBy('employee_id');

        $rows = $employees->map(function (Employee $emp) use ($logsByEmployee, $date, $isRestDay) {
            $group = $logsByEmployee->get($emp->id) ?? collect();

            // Pair events into sessions and keep those that STARTED on the selected date.
            $sessions = WorkSessions::startingOn(WorkSessions::pair($group), $date);

            $minutes = WorkSessions::workedMinutes($sessions);
            $open = WorkSessions::hasOpen($sessions);

            // Weekday: first 8h regular, remainder overtime.
            // Weekend (rest day): every worked minute is overtime.
            $split = WorkHours::split($minutes, $isRestDay);

            // The day's closing punch is where an HR verification is recorded.
            // A day of 13h+ is unusual and must be signed off before it's trusted.
            $closingOut = WorkSessions::closingOut($sessions);
            $needsVerification = WorkHours::needsVerification($minutes) && $closingOut !== null;

            // Punches whose location evidence needs HR's eye (outside every
            // fence, no GPS, weak fix) — regardless of which session they fall in.
            $locationExceptions = $group->filter(fn (AttendanceLog $l) => $l->hasLocationException())->values();

            // Weekend site work is paid only through an approved rest-day OT
            // request, so surface anyone working today without one.
            $restDayRequest = $isRestDay && $sessions
                ? $emp->overtimeRequests()->whereDate('ot_date', $date)->whereIn('status', ['pending', 'approved'])->first()
                : null;

            return [
                'employee' => $emp,
                'rest_day_request' => $restDayRequest,
                'location_exceptions' => $locationExceptions,
                'time_in' => $sessions[0]['in'] ?? null,
                'time_out' => $closingOut,
                'minutes' => $minutes,
                'regular_minutes' => $split['regular'],
                'ot_minutes' => $split['overtime'],
                'rest_day' => $isRestDay,
                'sessions' => count($sessions),
                'open' => $open,
                'needs_verification' => $needsVerification,
                'verify_log_id' => $closingOut?->id,
                'verification_status' => $closingOut?->ot_verification_status,
                'verification_remarks' => $closingOut?->ot_remarks,
                'verified_by' => $closingOut?->otVerifier?->name,
                'verified_at' => $closingOut?->ot_verified_at,
            ];
        });

        $present = $rows->filter(fn ($r) => $r['time_in'])->count();

        return view('attendance.monitor', [
            'rows' => $rows,
            'date' => $date,
            'search' => $search,
            'present' => $present,
            'absent' => $rows->count() - $present,
            'isRestDay' => $isRestDay,
        ]);
    }

    /**
     * Per-employee monthly timesheet: one row per calendar day with the
     * first time-in, closing time-out, hours (regular / OT), location
     * status and leave, plus month totals. Employees may view their own;
     * HR/Admin anyone's.
     */
    public function timesheet(Request $request, Employee $employee)
    {
        $user = $request->user();
        abort_unless($employee->id === $user->employee?->id || $user->can('view team reports'), 403);

        $month = $request->filled('month')
            ? Carbon::createFromFormat('Y-m', $request->input('month'))->startOfMonth()
            : Carbon::today()->startOfMonth();
        $monthEnd = $month->copy()->endOfMonth();
        $today = Carbon::today();

        // Window runs one day past the month so an overnight shift that starts
        // on the last day can still find its time-out.
        $logs = $employee->attendanceLogs()
            ->whereBetween('logged_at', [$month->copy()->startOfDay(), $monthEnd->copy()->addDay()->endOfDay()])
            ->with(['site:id,name', 'otVerifier:id,name'])
            ->orderBy('logged_at')
            ->get();
        $allSessions = WorkSessions::pair($logs);

        $leaves = $employee->leaveRequests()
            ->where('status', 'approved')
            ->where('date_from', '<=', $monthEnd->toDateString())
            ->where('date_to', '>=', $month->toDateString())
            ->get();

        $approvedOt = $employee->overtimeRequests()
            ->where('status', 'approved')
            ->whereBetween('ot_date', [$month->toDateString(), $monthEnd->toDateString()])
            ->get()
            ->keyBy(fn ($ot) => $ot->ot_date->toDateString());

        $hasFixedSchedule = (bool) $employee->schedule?->time_in;

        $days = [];
        $totals = ['worked' => 0, 'regular' => 0, 'overtime' => 0, 'present' => 0, 'absent' => 0, 'leave' => 0, 'exceptions' => 0];

        for ($d = $month->copy(); $d->lte($monthEnd); $d->addDay()) {
            $date = $d->toDateString();
            $isRestDay = $d->isWeekend();
            $sessions = WorkSessions::startingOn($allSessions, $date);
            $minutes = WorkSessions::workedMinutes($sessions);
            $split = WorkHours::split($minutes, $isRestDay);
            $in = $sessions[0]['in'] ?? null;
            $out = WorkSessions::closingOut($sessions);
            $leave = $leaves->first(fn ($l) => $l->date_from->lte($d) && $l->date_to->gte($d));

            $punches = collect($sessions)->flatMap(fn ($s) => [$s['in'], $s['out']])->filter();
            $exception = $punches->first(fn (AttendanceLog $l) => $l->hasLocationException());

            $status = match (true) {
                $in && $out => 'present',
                $in && ! $out => $d->isToday() ? 'open' : 'incomplete',
                (bool) $leave => 'leave',
                $isRestDay => 'rest',
                $d->gt($today) => 'upcoming',
                default => 'absent',
            };

            if (in_array($status, ['present', 'incomplete', 'open'], true)) {
                $totals['present']++;
            } elseif ($status === 'absent') {
                $totals['absent']++;
            } elseif ($status === 'leave') {
                $totals['leave']++;
            }
            $totals['worked'] += $minutes;
            $totals['regular'] += $split['regular'];
            $totals['overtime'] += $split['overtime'];
            if ($exception) {
                $totals['exceptions']++;
            }

            $days[] = [
                'date' => $d->copy(),
                'rest_day' => $isRestDay,
                'status' => $status,
                'in' => $in,
                'out' => $out,
                'sessions' => count($sessions),
                'minutes' => $minutes,
                'regular' => $split['regular'],
                'overtime' => $split['overtime'],
                'leave' => $leave,
                'ot_request' => $approvedOt->get($date),
                'exception' => $exception,
                'site' => $in?->site?->name,
            ];
        }

        // Expected working days so far (Mon–Fri up to today, within the month)
        $expected = 0;
        for ($d = $month->copy(); $d->lte($monthEnd) && $d->lte($today); $d->addDay()) {
            if (! $d->isWeekend()) {
                $expected++;
            }
        }

        return view('attendance.timesheet', [
            'employee' => $employee->loadMissing('schedule'),
            'month' => $month,
            'days' => $days,
            'totals' => $totals,
            'expectedDays' => $expected,
            'hasFixedSchedule' => $hasFixedSchedule,
            'canReview' => $user->can('view team reports'),
        ]);
    }

    /**
     * HR verifies (approves or rejects) an unusually long day's overtime and
     * records the reason. The decision is stamped on the day's clock-out log.
     */
    public function verify(Request $request, AttendanceLog $log)
    {
        abort_unless($log->log_type === 'time_out', 422, 'Verification attaches to a clock-out event.');

        $data = $request->validate([
            'decision' => ['required', 'in:approved,rejected'],
            'remarks' => ['required', 'string', 'max:500'],
        ]);

        $log->update([
            'ot_verification_status' => $data['decision'],
            'ot_remarks' => $data['remarks'],
            'ot_verified_by' => $request->user()->id,
            'ot_verified_at' => now(),
        ]);

        $log->employee->user?->notify(
            new RequestReviewed('Overtime', $data['decision'], route('attendance.index')),
        );

        $verb = $data['decision'] === 'approved' ? 'approved' : 'rejected';

        return back()->with('status', "Overtime {$verb} for ".optional($log->employee)->full_name.'.');
    }

    /**
     * HR reviews a punch whose location could not be verified (outside every
     * geofence, GPS unavailable, or a low-accuracy fix) and approves or
     * rejects it as legitimate attendance.
     */
    public function verifyLocation(Request $request, AttendanceLog $log)
    {
        abort_unless($log->hasLocationException(), 422, 'This punch has no location exception to review.');

        $data = $request->validate([
            'decision' => ['required', 'in:approved,rejected'],
            'remarks' => ['nullable', 'string', 'max:500'],
        ]);

        $log->update([
            'location_verification_status' => $data['decision'],
            'location_remarks' => $data['remarks'] ?? null,
            'location_verified_by' => $request->user()->id,
            'location_verified_at' => now(),
        ]);

        $log->employee->user?->notify(
            new RequestReviewed('Attendance location', $data['decision'], route('attendance.index')),
        );

        $when = $log->logged_at->format('M j, g:i A');

        return back()->with('status', "Location {$data['decision']} for ".optional($log->employee)->full_name." ({$when}).");
    }

    /** Let everyone who can review attendance know about a location exception. */
    private function notifyReviewers(AttendanceLog $log): void
    {
        $employee = $log->employee;
        $reviewers = User::permission('approve requests')
            ->where('id', '!=', $employee->user_id)
            ->get();

        foreach ($reviewers as $reviewer) {
            $reviewer->notify(new LocationExceptionFlagged($log));
        }
    }

    /** Decode a base64 data-URL selfie and store it on the public disk. */
    private function storePhoto(string $dataUrl, int $employeeId): ?string
    {
        $decoded = DataUrlPhoto::decode($dataUrl);
        if ($decoded === null) {
            return null;
        }

        $path = "attendance/{$employeeId}/".now()->format('Ymd_His').'_'.Str::random(6).'.'.$decoded['ext'];
        Storage::disk('public')->put($path, $decoded['binary']);

        return $path;
    }
}
