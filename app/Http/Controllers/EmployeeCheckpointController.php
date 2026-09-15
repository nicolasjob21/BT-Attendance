<?php

namespace App\Http\Controllers;

use App\Models\Checkpoint;
use App\Models\CheckpointCampaign;
use App\Services\Checkpoint\CheckpointAudit;
use App\Services\Checkpoint\CheckpointDispatcher;
use App\Services\Checkpoint\CheckpointPhoto;
use App\Services\Checkpoint\CheckpointVerifier;
use App\Services\GeofenceService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/** Employee side: respond to the active checkpoint, see own results, explain a miss. */
class EmployeeCheckpointController extends Controller
{
    public function __construct(private CheckpointDispatcher $dispatcher) {}

    public function index(Request $request)
    {
        $employee = $request->user()->employee;
        abort_unless($employee, 403, 'No employee profile linked to this account.');

        $this->dispatcher->sweep();

        $active = $employee->checkpoints()
            ->whereHas('campaign', fn ($q) => $q->live())
            ->with(['campaign', 'site:id,name'])->get();
        $recent = $employee->checkpoints()
            ->whereHas('campaign', fn ($q) => $q->whereNotIn('status', [CheckpointCampaign::ACTIVE, CheckpointCampaign::PAUSED]))
            ->with(['site:id,name', 'campaign:id,name,starts_at,expires_at,status'])
            ->latest('updated_at')->paginate(20);

        return view('checkpoints.my.index', compact('active', 'recent'));
    }

    /**
     * JSON polled by every page: the employee's currently active checkpoint
     * (if any) so the layout can pop the modal / browser notification.
     */
    public function active(Request $request)
    {
        $employee = $request->user()->employee;
        if (! $employee) {
            return response()->json(['active' => null]);
        }
        $this->dispatcher->sweep();

        $cp = $employee->checkpoints()
            ->whereIn('status', Checkpoint::WAITING_STATUSES)
            ->whereHas('campaign', fn ($q) => $q->where('status', CheckpointCampaign::ACTIVE))
            ->with(['campaign', 'site:id,name'])->latest('id')->first();

        return response()->json([
            'server_now' => Carbon::now()->toIso8601String(),
            'active' => $cp ? [
                'id' => $cp->id,
                'reference' => $cp->reference(),
                'site' => $cp->site?->name,
                'instruction' => $cp->campaign->instruction,
                'starts_at' => $cp->campaign->starts_at->toIso8601String(),
                'expires_at' => $cp->campaign->expires_at->toIso8601String(),
                'status' => $cp->status,
                'url' => route('my-checkpoints.show', $cp),
                'message' => 'Live presence checkpoint active. Please complete your verification before '.$cp->campaign->expires_at->format('g:i A').'.',
            ] : null,
        ]);
    }

    /** Live verification page while open; result summary afterwards. */
    public function show(Request $request, Checkpoint $checkpoint, GeofenceService $geofence, CheckpointPhoto $photos)
    {
        $employee = $request->user()->employee;
        abort_unless($employee && $checkpoint->employee_id === $employee->id, 403);

        $this->dispatcher->sweep();
        $checkpoint->refresh()->load(['site', 'campaign', 'matchedSite:id,name', 'reviewer:id,name']);
        $campaign = $checkpoint->campaign;

        if (! $checkpoint->seen_at) {
            $checkpoint->update(['seen_at' => Carbon::now()]);
        }

        if ($campaign->isLive() && ! $checkpoint->isCompleted()) {
            return view('checkpoints.my.verify', [
                'checkpoint' => $checkpoint,
                'campaign' => $campaign,
                'site' => $checkpoint->site->only(['id', 'name', 'address', 'latitude', 'longitude', 'geofence_radius_m']),
                'employeeName' => $employee->full_name,
                'minAccuracy' => $geofence->minAccuracyMeters(),
                'serverNow' => Carbon::now()->toIso8601String(),
                'expiresAt' => $campaign->expires_at->toIso8601String(),
                'paused' => $campaign->status === CheckpointCampaign::PAUSED,
            ]);
        }

        return view('checkpoints.my.result', [
            'checkpoint' => $checkpoint,
            'campaign' => $campaign,
            'hasPhoto' => $photos->exists($checkpoint),
            'canExplain' => $checkpoint->isNonCompliant() && ! $checkpoint->reviewed_at,
        ]);
    }

    public function submit(Request $request, Checkpoint $checkpoint, CheckpointVerifier $verifier)
    {
        $employee = $request->user()->employee;
        abort_unless($employee && $checkpoint->employee_id === $employee->id, 403);

        $data = $request->validate([
            'latitude' => ['nullable', 'required_with:longitude', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'required_with:latitude', 'numeric', 'between:-180,180'],
            'gps_accuracy' => ['nullable', 'numeric', 'min:0'],
            'photo' => ['required', 'string'],
            'client_timestamp' => ['nullable', 'string', 'max:40'],
            'network_status' => ['nullable', 'in:online,offline_synced'],
        ], ['photo.required' => 'Take a live photo before submitting.']);

        $cp = $verifier->submit($checkpoint, $employee, [
            'latitude' => isset($data['latitude']) ? (float) $data['latitude'] : null,
            'longitude' => isset($data['longitude']) ? (float) $data['longitude'] : null,
            'accuracy' => isset($data['gps_accuracy']) ? (float) $data['gps_accuracy'] : null,
            'photo' => $data['photo'],
            'client_timestamp' => $data['client_timestamp'] ?? null,
            'network_status' => $data['network_status'] ?? 'online',
        ]);

        if ($cp->isCompleted()) {
            return redirect()->route('my-checkpoints.show', $cp)->with('status', 'Checkpoint completed — thank you. '.$cp->validation_message);
        }

        // Failed attempt while the window is still open: stay on the page to retry.
        return back()->withErrors(['attempt' => $cp->validation_message]);
    }

    /** Employee reports a device problem (camera denied, no GPS, no internet, device). */
    public function reportIssue(Request $request, Checkpoint $checkpoint, CheckpointAudit $audit)
    {
        $employee = $request->user()->employee;
        abort_unless($employee && $checkpoint->employee_id === $employee->id, 403);
        abort_if($checkpoint->isCompleted(), 422, 'This checkpoint is already completed.');

        $data = $request->validate(['issue' => ['required', 'in:'.implode(',', array_keys(Checkpoint::ISSUES))]]);

        $attrs = ['issue_reported' => $data['issue']];
        if ($data['issue'] === 'camera_denied' && $checkpoint->isWaiting()) {
            $attrs['status'] = Checkpoint::CAMERA_PERMISSION_DENIED;
            $attrs['failure_reason'] = 'photo_missing';
        } elseif ($data['issue'] === 'gps_unavailable' && $checkpoint->isWaiting()) {
            $attrs['status'] = Checkpoint::GPS_UNAVAILABLE;
            $attrs['failure_reason'] = 'gps_unavailable';
        }
        $checkpoint->update($attrs);
        $audit->checkpoint($checkpoint, 'issue_reported', $request->user(), ['issue' => $data['issue']]);

        if ($request->expectsJson()) {
            return response()->json(['ok' => true]);
        }

        return back()->with('status', 'Problem reported to HR: '.Checkpoint::ISSUES[$data['issue']].'.');
    }

    /** Employee's own explanation for a missed / failed checkpoint (after reconnecting, etc.). */
    public function explain(Request $request, Checkpoint $checkpoint, CheckpointAudit $audit)
    {
        $employee = $request->user()->employee;
        abort_unless($employee && $checkpoint->employee_id === $employee->id, 403);
        abort_unless($checkpoint->isNonCompliant() && ! $checkpoint->reviewed_at, 422, 'This checkpoint is not open for an explanation.');

        $data = $request->validate([
            'employee_explanation' => ['required', 'string', 'max:1000'],
            'issue' => ['nullable', 'in:'.implode(',', array_keys(Checkpoint::ISSUES))],
        ]);

        $checkpoint->update(array_filter([
            'employee_explanation' => $data['employee_explanation'],
            'issue_reported' => $data['issue'] ?? null,
        ]));
        $audit->checkpoint($checkpoint, 'explanation_added', $request->user(), array_filter(['issue' => $data['issue'] ?? null]));

        return back()->with('status', 'Your explanation was sent to HR.');
    }
}
