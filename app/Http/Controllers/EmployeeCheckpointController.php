<?php

namespace App\Http\Controllers;

use App\Models\Checkpoint;
use App\Services\Checkpoint\CheckpointAudit;
use App\Services\Checkpoint\CheckpointDispatcher;
use App\Services\Checkpoint\CheckpointPhoto;
use App\Services\Checkpoint\CheckpointVerifier;
use App\Services\GeofenceService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/** Employee side: respond to an open checkpoint, see own results, explain a miss. */
class EmployeeCheckpointController extends Controller
{
    public function __construct(private CheckpointDispatcher $dispatcher) {}

    public function index(Request $request)
    {
        $employee = $request->user()->employee;
        abort_unless($employee, 403, 'No employee profile linked to this account.');

        $this->dispatcher->sweep();

        $open = $employee->checkpoints()->status(Checkpoint::OPEN)->with('site:id,name')->orderBy('expires_at')->get();
        $recent = $employee->checkpoints()
            ->whereNotIn('verification_status', [Checkpoint::SCHEDULED, Checkpoint::OPEN, Checkpoint::CANCELLED])
            ->with(['site:id,name', 'campaign:id,name'])
            ->latest('scheduled_at')->paginate(20);

        return view('checkpoints.my.index', compact('open', 'recent'));
    }

    /** Live verification page while open; result summary afterwards. */
    public function show(Request $request, Checkpoint $checkpoint, GeofenceService $geofence, CheckpointPhoto $photos)
    {
        $employee = $request->user()->employee;
        abort_unless($employee && $checkpoint->employee_id === $employee->id, 403);

        $this->dispatcher->sweep();
        $checkpoint->refresh()->load(['site', 'campaign:id,name,response_window_minutes,status', 'matchedSite:id,name', 'reviewer:id,name']);

        // Upcoming checkpoints are secret — an employee can only see one once it opened.
        abort_if($checkpoint->verification_status === Checkpoint::SCHEDULED, 404);

        if ($checkpoint->isOpen()) {
            $site = $checkpoint->site;

            return view('checkpoints.my.verify', [
                'checkpoint' => $checkpoint,
                'site' => $site->only(['id', 'name', 'address', 'latitude', 'longitude', 'geofence_radius_m']),
                'employeeName' => $employee->full_name,
                'minAccuracy' => $geofence->minAccuracyMeters(),
                'serverNow' => Carbon::now()->toIso8601String(),
                'expiresAt' => $checkpoint->expires_at->toIso8601String(),
            ]);
        }

        return view('checkpoints.my.result', [
            'checkpoint' => $checkpoint,
            'hasPhoto' => $photos->exists($checkpoint),
            'canExplain' => $checkpoint->isException() && $checkpoint->review_status !== 'reviewed',
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

        $message = match ($cp->verification_status) {
            Checkpoint::VERIFIED => 'Checkpoint verified — thank you. '.$cp->validation_message,
            Checkpoint::PENDING_REVIEW => 'Checkpoint submitted, but your location could not be fully verified. HR will review it — you may add an explanation below.',
            default => 'Checkpoint submitted, but you were outside the project site geofence. HR has been notified — please add an explanation below.',
        };

        return redirect()->route('my-checkpoints.show', $cp)->with('status', $message);
    }

    /** Employee's own explanation for a missed / failed / pending checkpoint. */
    public function explain(Request $request, Checkpoint $checkpoint, CheckpointAudit $audit)
    {
        $employee = $request->user()->employee;
        abort_unless($employee && $checkpoint->employee_id === $employee->id, 403);
        abort_unless($checkpoint->isException() && $checkpoint->review_status !== 'reviewed', 422, 'This checkpoint is not open for an explanation.');

        $data = $request->validate(['employee_explanation' => ['required', 'string', 'max:1000']]);

        $checkpoint->update(['employee_explanation' => $data['employee_explanation']]);
        $audit->checkpoint($checkpoint, 'explanation_added', $request->user());

        return back()->with('status', 'Your explanation was sent to HR.');
    }
}
