<?php

namespace App\Http\Controllers;

use App\Models\Checkpoint;
use App\Models\CheckpointCampaign;
use App\Models\Employee;
use App\Models\Site;
use App\Services\Checkpoint\CampaignManager;
use App\Services\Checkpoint\CheckpointAudit;
use App\Services\Checkpoint\CheckpointDispatcher;
use App\Services\Checkpoint\CheckpointScheduler;
use App\Services\Checkpoint\CheckpointSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Check Point module: dashboard, campaign CRUD and lifecycle controls. */
class CheckpointCampaignController extends Controller
{
    public function __construct(
        private CampaignManager $manager,
        private CheckpointDispatcher $dispatcher,
    ) {}

    /** Module dashboard: summary cards, live campaigns, pending reviews, activity. */
    public function index(Request $request)
    {
        $this->dispatcher->sweep();

        $live = CheckpointCampaign::status([CheckpointCampaign::ACTIVE, CheckpointCampaign::PAUSED])
            ->with('site:id,name')->withCount('participants')
            ->orderByRaw("status = 'active' desc")->latest('activated_at')->get();
        $scheduled = CheckpointCampaign::status([CheckpointCampaign::SCHEDULED, CheckpointCampaign::DRAFT])
            ->with('site:id,name')->withCount('participants')
            ->orderBy('start_date')->get();
        $recentHistory = CheckpointCampaign::history()
            ->with(['site:id,name', 'closer:id,name'])->withCount('participants')
            ->latest('updated_at')->take(5)->get();

        $liveIds = $live->pluck('id');
        $counts = Checkpoint::whereIn('campaign_id', $liveIds)
            ->select('verification_status', DB::raw('count(*) as n'))
            ->groupBy('verification_status')->pluck('n', 'verification_status');

        $stats = [
            'active_campaigns' => $live->where('status', CheckpointCampaign::ACTIVE)->count(),
            'paused_campaigns' => $live->where('status', CheckpointCampaign::PAUSED)->count(),
            'employees' => DB::table('checkpoint_campaign_participants')->whereIn('campaign_id', $liveIds)->distinct()->count('employee_id'),
            'generated' => (int) $counts->sum(),
            'verified' => (int) ($counts[Checkpoint::VERIFIED] ?? 0),
            'missed' => (int) ($counts[Checkpoint::MISSED] ?? 0) + (int) ($counts[Checkpoint::EXPIRED] ?? 0),
            'failed' => (int) ($counts[Checkpoint::FAILED] ?? 0),
            'open' => (int) ($counts[Checkpoint::OPEN] ?? 0),
            'pending_reviews' => Checkpoint::pendingReview()->count(),
        ];

        $pending = Checkpoint::pendingReview()
            ->with(['employee:id,first_name,last_name,employee_no', 'site:id,name', 'campaign:id,name'])
            ->latest('scheduled_at')->take(15)->get();

        $activity = Checkpoint::whereNotIn('verification_status', [Checkpoint::SCHEDULED])
            ->with(['employee:id,first_name,last_name,employee_no', 'site:id,name', 'campaign:id,name', 'reviewer:id,name'])
            ->latest('updated_at')->take(15)->get();

        // Employees with 2+ exceptions in the last 30 days.
        $repeat = Checkpoint::exceptions()
            ->where('scheduled_at', '>=', Carbon::now()->subDays(30))
            ->select('employee_id', DB::raw('count(*) as exceptions'))
            ->groupBy('employee_id')->having('exceptions', '>=', 2)
            ->orderByDesc('exceptions')->take(8)
            ->with('employee:id,first_name,last_name,employee_no')->get();

        return view('checkpoints.index', compact('live', 'scheduled', 'recentHistory', 'stats', 'pending', 'activity', 'repeat'));
    }

    /** Full campaign history (completed / cancelled), paginated. */
    public function history(Request $request)
    {
        $status = $request->input('status');
        $campaigns = CheckpointCampaign::query()
            ->when($status && isset(CheckpointCampaign::STATUSES[$status]), fn ($q) => $q->where('status', $status), fn ($q) => $q->history())
            ->with(['site:id,name', 'creator:id,name', 'closer:id,name'])->withCount('participants')
            ->withCount(['checkpoints as verified_count' => fn ($q) => $q->where('verification_status', Checkpoint::VERIFIED)])
            ->withCount(['checkpoints as exception_count' => fn ($q) => $q->exceptions()])
            ->latest('updated_at')->paginate(20)->withQueryString();

        return view('checkpoints.history', compact('campaigns', 'status'));
    }

    public function create(CheckpointSettings $settings)
    {
        return view('checkpoints.create', $this->formData($settings) + ['campaign' => null]);
    }

    public function store(Request $request, CheckpointScheduler $scheduler)
    {
        [$attributes, $employees] = $this->validated($request, $scheduler);
        $campaign = $this->manager->create($attributes, $employees, $request->user());

        return redirect()->route('checkpoints.show', $campaign)
            ->with('status', 'Campaign saved as a draft. Review the configuration below, then activate or schedule it.');
    }

    public function edit(CheckpointCampaign $campaign, CheckpointSettings $settings)
    {
        abort_unless($campaign->canEdit(), 422, 'Only draft or scheduled campaigns can be edited.');
        $campaign->load('employees:id');

        return view('checkpoints.create', $this->formData($settings) + ['campaign' => $campaign]);
    }

    public function update(Request $request, CheckpointCampaign $campaign, CheckpointScheduler $scheduler)
    {
        [$attributes, $employees] = $this->validated($request, $scheduler);
        $this->manager->update($campaign, $attributes, $employees, $request->user());

        return redirect()->route('checkpoints.show', $campaign)->with('status', 'Campaign configuration updated.');
    }

    /** Campaign detail: configuration review, controls, per-day results, audit trail. */
    public function show(Request $request, CheckpointCampaign $campaign)
    {
        $this->dispatcher->sweep();

        $campaign->load(['site', 'creator:id,name', 'activator:id,name', 'closer:id,name', 'employees' => fn ($q) => $q->orderBy('first_name')]);

        $counts = $campaign->checkpoints()
            ->select('verification_status', DB::raw('count(*) as n'))
            ->groupBy('verification_status')->pluck('n', 'verification_status');

        $checkpoints = $campaign->checkpoints()
            ->whereNotIn('verification_status', [Checkpoint::SCHEDULED]) // never expose upcoming times
            ->with(['employee:id,first_name,last_name,employee_no', 'reviewer:id,name'])
            ->latest('scheduled_at')->paginate(25)->withQueryString();

        // How many secret checkpoints are still queued today (count only).
        $upcomingToday = $campaign->checkpoints()->status(Checkpoint::SCHEDULED)
            ->whereDate('scheduled_for', Carbon::today())->count();

        $audit = $campaign->auditLogs()->with('user:id,name')->take(30)->get();

        return view('checkpoints.show', compact('campaign', 'counts', 'checkpoints', 'upcomingToday', 'audit'));
    }

    public function activate(Request $request, CheckpointCampaign $campaign)
    {
        $this->manager->activate($campaign, $request->user());
        $campaign->refresh();

        $msg = $campaign->status === CheckpointCampaign::ACTIVE
            ? 'Campaign activated. Random checkpoints for today have been generated — employees will be notified as each one opens.'
            : 'Campaign scheduled. It will start automatically on '.$campaign->start_date->format('M j, Y').'.';

        return redirect()->route('checkpoints.show', $campaign)->with('status', $msg);
    }

    public function pause(Request $request, CheckpointCampaign $campaign)
    {
        $this->manager->pause($campaign, $request->user(), $request->input('reason'));

        return back()->with('status', 'Campaign paused. No new checkpoints will open until it is resumed.');
    }

    public function resume(Request $request, CheckpointCampaign $campaign)
    {
        $this->manager->resume($campaign, $request->user());

        return back()->with('status', 'Campaign resumed.');
    }

    public function end(Request $request, CheckpointCampaign $campaign)
    {
        $this->manager->end($campaign, $request->user(), $request->input('reason'));

        return back()->with('status', 'Campaign ended. Results and evidence are kept in the campaign history.');
    }

    public function cancel(Request $request, CheckpointCampaign $campaign)
    {
        $this->manager->cancel($campaign, $request->user(), $request->input('reason'));

        return redirect()->route('checkpoints.index')->with('status', 'Campaign cancelled.');
    }

    public function close(Request $request, CheckpointCampaign $campaign)
    {
        $this->manager->close($campaign, $request->user());

        return back()->with('status', 'Campaign closed.');
    }

    /** CSV export of every checkpoint in the campaign (upcoming times excluded). */
    public function export(Request $request, CheckpointCampaign $campaign): StreamedResponse
    {
        app(CheckpointAudit::class)->campaign($campaign, 'exported', $request->user());

        $filename = 'checkpoints-'.$campaign->id.'-'.now()->format('Ymd_His').'.csv';
        $rows = $campaign->checkpoints()
            ->whereNotIn('verification_status', [Checkpoint::SCHEDULED])
            ->with(['employee:id,first_name,last_name,employee_no', 'site:id,name', 'matchedSite:id,name', 'reviewer:id,name'])
            ->orderBy('scheduled_at');

        return response()->streamDownload(function () use ($rows, $campaign) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Checkpoint', 'Campaign', 'Employee No.', 'Employee', 'Project site', 'Scheduled', 'Opened', 'Expires', 'Submitted',
                'Status', 'Result', 'Latitude', 'Longitude', 'GPS accuracy (m)', 'Distance (m)', 'Within geofence', 'Matched site',
                'Network', 'Employee explanation', 'Review status', 'Review result', 'Review remarks', 'Reviewed by', 'Reviewed at']);
            $rows->chunk(200, function ($chunk) use ($out, $campaign) {
                foreach ($chunk as $cp) {
                    fputcsv($out, [
                        $cp->reference(), $campaign->name, $cp->employee?->employee_no, $cp->employee?->full_name, $cp->site?->name,
                        $cp->scheduled_at?->toDateTimeString(), $cp->opened_at?->toDateTimeString(), $cp->expires_at?->toDateTimeString(), $cp->submitted_at?->toDateTimeString(),
                        $cp->status_label, $cp->result_label, $cp->latitude, $cp->longitude, $cp->gps_accuracy_meters, $cp->distance_from_site_meters,
                        $cp->within_geofence === null ? '' : ($cp->within_geofence ? 'yes' : 'no'), $cp->matchedSite?->name,
                        $cp->network_status, $cp->employee_explanation, $cp->review_status, $cp->review_result_label, $cp->review_remarks,
                        $cp->reviewer?->name, $cp->reviewed_at?->toDateTimeString(),
                    ]);
                }
            });
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    // ── helpers ─────────────────────────────────────────────────────

    private function formData(CheckpointSettings $settings): array
    {
        return [
            'sites' => Site::query()->where('status', 'active')->orderByRaw("type = 'project_site' desc")->orderBy('name')->get(['id', 'name', 'type', 'geofence_radius_m']),
            'employees' => Employee::query()->where('status', 'active')->with('activeAssignment.site:id,name')
                ->orderBy('first_name')->orderBy('last_name')->get(['id', 'first_name', 'last_name', 'employee_no', 'employee_type']),
            'defaults' => $settings->defaults(),
            'instructionLibrary' => $settings->photoInstructions(),
        ];
    }

    /** @return array{0: array, 1: array<int>} */
    private function validated(Request $request, CheckpointScheduler $scheduler): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'project_site_id' => ['required', 'exists:sites,id'],
            'reason' => ['required', 'string', 'max:1000'],
            'employees' => ['required', 'array', 'min:1'],
            'employees.*' => ['integer', 'exists:employees,id'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'working_start_time' => ['required', 'date_format:H:i'],
            'working_end_time' => ['required', 'date_format:H:i', 'after:working_start_time'],
            'include_weekends' => ['sometimes', 'boolean'],
            'checkpoints_per_day' => ['required', 'integer', 'between:1,12'],
            'minimum_interval_minutes' => ['required', 'integer', 'between:5,720'],
            'maximum_interval_minutes' => ['required', 'integer', 'between:5,720', 'gte:minimum_interval_minutes'],
            'response_window_minutes' => ['required', 'integer', 'between:3,60'],
            'photo_instructions' => ['required', 'array', 'min:1'],
            'photo_instructions.*' => ['string', 'max:150'],
        ], [
            'employees.required' => 'Select at least one employee to cover.',
            'photo_instructions.required' => 'Pick at least one photo instruction.',
            'working_end_time.after' => 'Working hours must end after they start.',
            'maximum_interval_minutes.gte' => 'Maximum interval must be at least the minimum interval.',
        ]);

        $windowMinutes = (int) Carbon::parse($data['working_start_time'])->diffInMinutes(Carbon::parse($data['working_end_time']));
        $problem = $scheduler->validate(
            $windowMinutes,
            (int) $data['checkpoints_per_day'],
            (int) $data['minimum_interval_minutes'],
            (int) $data['maximum_interval_minutes'],
            (int) $data['response_window_minutes'],
        );
        if ($problem) {
            throw ValidationException::withMessages(['checkpoints_per_day' => $problem]);
        }

        $employees = array_map('intval', $data['employees']);
        unset($data['employees']);
        $data['include_weekends'] = (bool) ($data['include_weekends'] ?? false);
        $data['photo_instructions'] = array_values(array_unique(array_filter(array_map('trim', $data['photo_instructions']))));

        return [$data, $employees];
    }
}
