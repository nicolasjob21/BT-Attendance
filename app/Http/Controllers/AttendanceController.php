<?php

namespace App\Http\Controllers;

use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\Site;
use App\Support\Geo;
use App\Support\WorkHours;
use App\Support\WorkSessions;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AttendanceController extends Controller
{
    /** Clock in/out capture screen. */
    public function create(Request $request)
    {
        $employee = $request->user()->employee;
        abort_unless($employee, 403, 'No employee profile linked to this account.');

        $lastLog = $employee->attendanceLogs()->latest('logged_at')->first();
        $nextAction = ($lastLog && $lastLog->log_type === 'time_in') ? 'time_out' : 'time_in';

        // Registered work sites drive the on-map geofences the employee must stand in.
        $sites = Site::query()
            ->get(['id', 'name', 'latitude', 'longitude', 'geofence_radius_m']);

        $enforceGeofence = (bool) config('attendance.enforce_geofence');

        return view('attendance.create', compact('lastLog', 'nextAction', 'sites', 'enforceGeofence'));
    }

    /** Persist a clock event with the captured GPS location. */
    public function store(Request $request)
    {
        $employee = $request->user()->employee;
        abort_unless($employee, 403);

        $data = $request->validate([
            'log_type' => ['required', 'in:time_in,time_out'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'photo' => ['required', 'string'], // base64 data URL from the camera
            'synced_offline' => ['sometimes', 'boolean'],
        ]);

        // Server-side geofence check. The coordinates are validated here against
        // the registered work sites regardless of what the client sent, so a
        // spoofed or hand-placed pin can still be caught.
        [$site, $distance, $withinGeofence] = $this->matchGeofence(
            (float) $data['latitude'],
            (float) $data['longitude'],
        );

        if (config('attendance.enforce_geofence') && ! $withinGeofence) {
            $howFar = $distance !== null
                ? 'about ' . number_format($distance) . ' m from the nearest work site'
                : 'outside every registered work site';

            return back()->withErrors([
                'latitude' => "You appear to be {$howFar}. You must be on-site to "
                    . ($data['log_type'] === 'time_in' ? 'clock in.' : 'clock out.'),
            ]);
        }

        $photoPath = $this->storePhoto($data['photo'], $employee->id);

        AttendanceLog::create([
            'employee_id' => $employee->id,
            'site_id' => $site?->id,
            'log_type' => $data['log_type'],
            'logged_at' => Carbon::now(),
            'latitude' => $data['latitude'],
            'longitude' => $data['longitude'],
            'distance_m' => $distance !== null ? round($distance, 2) : null,
            'within_geofence' => $withinGeofence,
            'photo_path' => $photoPath,
            'synced_offline' => (bool) ($data['synced_offline'] ?? false),
        ]);

        $verb = $data['log_type'] === 'time_in' ? 'Clocked in' : 'Clocked out';

        return redirect()->route('attendance.index')
            ->with('status', "{$verb} at " . Carbon::now()->format('g:i A') . '.');
    }

    /** Personal attendance history. */
    public function index(Request $request)
    {
        $employee = $request->user()->employee;
        abort_unless($employee, 403);

        $logs = $employee->attendanceLogs()
            ->with('site')
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
            ->with('otVerifier:id,name')
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

            return [
                'employee' => $emp,
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

        $verb = $data['decision'] === 'approved' ? 'approved' : 'rejected';

        return back()->with('status', "Overtime {$verb} for " . optional($log->employee)->full_name . '.');
    }

    /**
     * Find the nearest registered site to a punch and decide if it falls inside
     * that site's geofence.
     *
     * @return array{0: ?Site, 1: ?float, 2: bool}  [nearestSite, distanceMetres, withinGeofence]
     */
    private function matchGeofence(float $lat, float $lng): array
    {
        $nearest = null;
        $nearestDistance = null;

        foreach (Site::all() as $site) {
            $distance = Geo::distanceMeters($lat, $lng, (float) $site->latitude, (float) $site->longitude);
            if ($nearestDistance === null || $distance < $nearestDistance) {
                $nearest = $site;
                $nearestDistance = $distance;
            }
        }

        if ($nearest === null) {
            return [null, null, false];
        }

        $within = $nearestDistance <= (float) $nearest->geofence_radius_m;

        return [$nearest, $nearestDistance, $within];
    }

    /** Decode a base64 data-URL selfie and store it on the public disk. */
    private function storePhoto(string $dataUrl, int $employeeId): ?string
    {
        if (! Str::startsWith($dataUrl, 'data:image')) {
            return null;
        }

        [$meta, $content] = explode(',', $dataUrl, 2);
        $ext = Str::contains($meta, 'png') ? 'png' : 'jpg';
        $binary = base64_decode($content, true);
        if ($binary === false) {
            return null;
        }

        $path = "attendance/{$employeeId}/" . now()->format('Ymd_His') . '_' . Str::random(6) . '.' . $ext;
        Storage::disk('public')->put($path, $binary);

        return $path;
    }
}
