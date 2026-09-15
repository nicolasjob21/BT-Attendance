<?php

namespace App\Http\Controllers;

use App\Models\Checkpoint;
use App\Models\CheckpointCampaign;
use App\Models\Employee;
use App\Models\Site;
use App\Services\Checkpoint\CampaignManager;
use App\Services\Checkpoint\CheckpointAudit;
use App\Services\Checkpoint\CheckpointDispatcher;
use App\Services\Checkpoint\CheckpointSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Check Point module: dashboard, checkpoint CRUD, lifecycle controls, monitoring page. */
class CheckpointCampaignController extends Controller
{
    public function __construct(
        private CampaignManager $manager,
        private CheckpointDispatcher $dispatcher,
    ) {}

    /** Module dashboard: live checkpoints, drafts, follow-ups, recent history. */
    public function index()
    {
        $this->dispatcher->sweep();

        $withCounts = fn ($q) => $q->with('site:id,name')->withCount([
            'participants',
            'checkpoints as completed_count' => fn ($c) => $c->completed(),
            'checkpoints as non_compliant_count' => fn ($c) => $c->nonCompliant(),
        ]);

        $live = $withCounts(CheckpointCampaign::live())->orderByRaw("status = 'active' desc")->latest('starts_at')->get();
        $drafts = $withCounts(CheckpointCampaign::status(CheckpointCampaign::DRAFT))->orderByRaw('scheduled_start_at is null')->orderBy('scheduled_start_at')->latest('id')->get();
        $expired = $withCounts(CheckpointCampaign::status(CheckpointCampaign::EXPIRED))->latest('expires_at')->get();
        $recentHistory = $withCounts(CheckpointCampaign::history())->with('closer:id,name')->latest('updated_at')->take(5)->get();

        $followUps = Checkpoint::nonCompliant()->whereNull('reviewed_at')
            ->whereHas('campaign', fn ($q) => $q->whereIn('status', [CheckpointCampaign::EXPIRED, CheckpointCampaign::COMPLETED]))
            ->with(['employee:id,first_name,last_name,employee_no', 'site:id,name', 'campaign:id,name,expires_at'])
            ->latest('updated_at')->take(15)->get();

        $stats = [
            'active' => $live->where('status', CheckpointCampaign::ACTIVE)->count(),
            'paused' => $live->where('status', CheckpointCampaign::PAUSED)->count(),
            'employees_live' => DB::table('checkpoint_campaign_participants')->whereIn('campaign_id', $live->pluck('id'))->distinct()->count('employee_id'),
            'completed_live' => (int) $live->sum('completed_count'),
            'awaiting_followup' => Checkpoint::nonCompliant()->whereNull('reviewed_at')->count(),
            'pending_review' => Checkpoint::needsReview()->count(),
            'escalated' => Checkpoint::whereNotNull('escalated_at')->whereNull('reviewed_at')->count(),
            'expired_open' => $expired->count(),
        ];

        return view('checkpoints.index', compact('live', 'drafts', 'expired', 'recentHistory', 'followUps', 'stats'));
    }

    public function history(Request $request)
    {
        $status = $request->input('status');
        $siteId = $request->integer('site') ?: null;
        $from = $request->input('from');
        $to = $request->input('to');

        $campaigns = CheckpointCampaign::query()
            ->when($status && isset(CheckpointCampaign::STATUSES[$status]), fn ($q) => $q->where('status', $status), fn ($q) => $q->history())
            ->when($siteId, fn ($q, $v) => $q->where('project_site_id', $v))
            ->when($from, fn ($q, $v) => $q->whereDate('starts_at', '>=', Carbon::parse($v)->toDateString()))
            ->when($to, fn ($q, $v) => $q->whereDate('starts_at', '<=', Carbon::parse($v)->toDateString()))
            ->with(['site:id,name', 'creator:id,name', 'closer:id,name'])->withCount('participants')
            ->withCount(['checkpoints as completed_count' => fn ($q) => $q->completed()])
            ->withCount(['checkpoints as non_compliant_count' => fn ($q) => $q->nonCompliant()])
            ->latest('starts_at')->latest('id')->paginate(20)->withQueryString();

        $sites = Site::orderBy('name')->get(['id', 'name']);

        return view('checkpoints.history', compact('campaigns', 'status', 'siteId', 'from', 'to', 'sites'));
    }

    /**
     * Daily monitor: every checkpoint that ran on one day (optionally one
     * site) and an employee × checkpoint grid of their responses.
     */
    public function daily(Request $request)
    {
        $this->dispatcher->sweep();

        $date = $request->filled('date') ? Carbon::parse($request->input('date'))->startOfDay() : Carbon::today();
        $siteId = $request->integer('site') ?: null;
        $sites = Site::orderByRaw("type = 'project_site' desc")->orderBy('name')->get(['id', 'name', 'type']);

        $campaigns = CheckpointCampaign::query()
            ->whereNotNull('starts_at')
            ->whereBetween('starts_at', [$date, $date->copy()->endOfDay()])
            ->when($siteId, fn ($q, $v) => $q->where('project_site_id', $v))
            ->with(['site:id,name', 'checkpoints.employee:id,first_name,last_name,employee_no'])
            ->withCount([
                'participants',
                'checkpoints as completed_count' => fn ($c) => $c->completed(),
                'checkpoints as non_compliant_count' => fn ($c) => $c->nonCompliant(),
            ])
            ->orderBy('starts_at')->get();

        // Grid: one row per employee who was included in any checkpoint that
        // day, one column per checkpoint; cell = their response (or null).
        $grid = [];
        foreach ($campaigns as $c) {
            foreach ($c->checkpoints as $cp) {
                $emp = $cp->employee;
                if (! $emp) {
                    continue;
                }
                $grid[$emp->id]['employee'] ??= $emp;
                $grid[$emp->id]['cells'][$c->id] = $cp->setRelation('campaign', $c);
            }
        }
        uasort($grid, fn ($a, $b) => strcmp($a['employee']->full_name, $b['employee']->full_name));

        $totals = [
            'checkpoints' => $campaigns->count(),
            'employees' => count($grid),
            'completed' => (int) $campaigns->sum('completed_count'),
            'non_compliant' => (int) $campaigns->sum('non_compliant_count'),
            'open_follow_ups' => $campaigns->flatMap->checkpoints->filter(fn ($cp) => $cp->isNonCompliant() && ! $cp->reviewed_at)->count(),
        ];

        // Days around the selected one that have checkpoints, for quick jumping.
        $activeDays = CheckpointCampaign::query()
            ->whereNotNull('starts_at')
            ->when($siteId, fn ($q, $v) => $q->where('project_site_id', $v))
            ->whereBetween('starts_at', [$date->copy()->subDays(14), $date->copy()->addDays(14)->endOfDay()])
            ->get(['starts_at'])->map(fn ($c) => $c->starts_at->toDateString())->unique()->values();

        return view('checkpoints.daily', compact('date', 'siteId', 'sites', 'campaigns', 'grid', 'totals', 'activeDays'));
    }

    public function create(CheckpointSettings $settings)
    {
        return view('checkpoints.create', $this->formData($settings) + ['campaign' => null]);
    }

    public function store(Request $request)
    {
        [$attributes, $employees] = $this->validated($request);
        $campaign = $this->manager->create($attributes, $employees, $request->user());

        return redirect()->route('checkpoints.show', $campaign)
            ->with('status', 'Checkpoint saved as a draft. Review the employees below, then activate it now or set a start time.');
    }

    public function edit(CheckpointCampaign $campaign, CheckpointSettings $settings)
    {
        abort_unless($campaign->isDraft(), 422, 'Only a draft checkpoint can be edited.');
        $campaign->load('employees:id');

        return view('checkpoints.create', $this->formData($settings) + ['campaign' => $campaign]);
    }

    public function update(Request $request, CheckpointCampaign $campaign)
    {
        [$attributes, $employees] = $this->validated($request);
        $this->manager->update($campaign, $attributes, $employees, $request->user());

        return redirect()->route('checkpoints.show', $campaign)->with('status', 'Checkpoint updated.');
    }

    /**
     * Monitoring page: shared checkpoint info, live counters, and the two
     * tables (completed / pending & non-compliant). Also the draft review page.
     */
    public function show(CheckpointCampaign $campaign)
    {
        $this->dispatcher->sweep();
        $campaign->refresh()->load(['site', 'creator:id,name', 'activator:id,name', 'closer:id,name']);

        $employees = $campaign->employees()->with('activeAssignment.site:id,name')->orderBy('first_name')->orderBy('last_name')->get();

        $responses = $campaign->checkpoints()
            ->with(['employee:id,first_name,last_name,employee_no', 'matchedSite:id,name', 'reviewer:id,name'])
            ->get()
            ->sortBy(fn ($cp) => $cp->employee?->full_name);
        $responses->each->setRelation('campaign', $campaign);

        $completed = $responses->filter->isCompleted()->sortBy('submitted_at')->values();
        $pending = $responses->reject->isCompleted()->values();

        $counts = [
            'total' => $campaign->participants()->count(),
            'completed' => $completed->count(),
            'pending' => $responses->filter(fn ($cp) => in_array($cp->status, [Checkpoint::PENDING, Checkpoint::NOTIFIED], true))->count(),
            'missed' => $responses->where('status', Checkpoint::MISSED)->count(),
            'outside' => $responses->where('status', Checkpoint::OUTSIDE_GEOFENCE)->count(),
            'review' => $responses->where('status', Checkpoint::PENDING_REVIEW)->count(),
            'open_follow_ups' => $pending->filter(fn ($cp) => $cp->isNonCompliant() && ! $cp->reviewed_at)->count(),
        ];

        $audit = $campaign->auditLogs()->with('user:id,name')->take(40)->get();

        return view('checkpoints.show', compact('campaign', 'employees', 'completed', 'pending', 'counts', 'audit'));
    }

    /** Lightweight JSON for the monitoring page's live counters / countdown. */
    public function status(CheckpointCampaign $campaign)
    {
        $this->dispatcher->sweep();
        $campaign->refresh();
        $byStatus = $campaign->checkpoints()->select('status', DB::raw('count(*) as n'))->groupBy('status')->pluck('n', 'status');

        return response()->json([
            'status' => $campaign->status,
            'server_now' => Carbon::now()->toIso8601String(),
            'expires_at' => $campaign->expires_at?->toIso8601String(),
            'completed' => (int) ($byStatus[Checkpoint::RESPONDED] ?? 0) + (int) ($byStatus[Checkpoint::APPROVED_EXCEPTION] ?? 0),
            'total' => $campaign->participants()->count(),
            'changed_at' => $campaign->checkpoints()->max('updated_at'),
        ]);
    }

    public function activate(Request $request, CheckpointCampaign $campaign)
    {
        $campaign = $this->manager->activate($campaign, $request->user());

        return redirect()->route('checkpoints.show', $campaign)->with('status',
            'Checkpoint activated. Start '.$campaign->starts_at->format('g:i A').' · deadline '.$campaign->expires_at->format('g:i A')
            .' — the same for all '.$campaign->participants()->count().' employee(s). Notifications sent.');
    }

    public function schedule(Request $request, CheckpointCampaign $campaign)
    {
        $data = $request->validate(['scheduled_start_at' => ['required', 'date']]);
        $at = Carbon::parse($data['scheduled_start_at']);
        $this->manager->schedule($campaign, $at, $request->user());

        return back()->with('status', 'Checkpoint scheduled to start automatically at '.$at->format('M j, g:i A').'.');
    }

    public function pause(Request $request, CheckpointCampaign $campaign)
    {
        $this->manager->pause($campaign, $request->user(), $request->input('reason'));

        return back()->with('status', 'Checkpoint paused — the countdown is frozen and submissions are on hold.');
    }

    public function resume(Request $request, CheckpointCampaign $campaign)
    {
        $c = $this->manager->resume($campaign, $request->user());

        return back()->with('status', 'Checkpoint resumed. New deadline for everyone: '.$c->expires_at->format('g:i A').'.');
    }

    public function end(Request $request, CheckpointCampaign $campaign)
    {
        $this->manager->endNow($campaign, $request->user(), $this->dispatcher);

        return back()->with('status', 'Checkpoint window closed. Employees without a valid submission are now marked missed.');
    }

    public function cancel(Request $request, CheckpointCampaign $campaign)
    {
        $this->manager->cancel($campaign, $request->user(), $request->input('reason'));

        return redirect()->route('checkpoints.index')->with('status', 'Checkpoint cancelled.');
    }

    public function complete(Request $request, CheckpointCampaign $campaign)
    {
        $this->manager->complete($campaign, $request->user());

        return back()->with('status', 'Checkpoint marked as completed.');
    }

    /** CSV export of every employee response in the checkpoint. */
    public function export(Request $request, CheckpointCampaign $campaign, CheckpointAudit $audit): StreamedResponse
    {
        $audit->campaign($campaign, 'exported', $request->user());

        $filename = 'checkpoint-'.$campaign->id.'-'.now()->format('Ymd_His').'.csv';
        $rows = $campaign->checkpoints()
            ->with(['employee:id,first_name,last_name,employee_no', 'site:id,name', 'matchedSite:id,name', 'reviewer:id,name'])
            ->orderBy('status');

        return response()->streamDownload(function () use ($rows, $campaign) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Reference', 'Checkpoint', 'Project site', 'Start', 'Deadline', 'Employee No.', 'Employee', 'Status', 'Verification',
                'Notified', 'Seen', 'Attempts', 'Last attempt', 'Last attempt result', 'Submitted (server)', 'Response (s)', 'Latitude', 'Longitude',
                'GPS accuracy (m)', 'Distance (m)', 'Within geofence', 'Matched site', 'Network', 'Issue reported', 'Employee explanation',
                'HR reason', 'HR note', 'Escalated', 'Reviewed by', 'Reviewed at']);
            $rows->chunk(200, function ($chunk) use ($out, $campaign) {
                foreach ($chunk as $cp) {
                    $cp->setRelation('campaign', $campaign);
                    fputcsv($out, [
                        $cp->reference(), $campaign->name, $cp->site?->name, $campaign->starts_at?->toDateTimeString(), $campaign->expires_at?->toDateTimeString(),
                        $cp->employee?->employee_no, $cp->employee?->full_name, $cp->status_label, $cp->verification_label,
                        $cp->notified_at?->toDateTimeString(), $cp->seen_at?->toDateTimeString(), $cp->submission_attempts, $cp->last_attempt_at?->toDateTimeString(),
                        $cp->last_attempt_result, $cp->submitted_at?->toDateTimeString(), $cp->responseSeconds(), $cp->latitude, $cp->longitude,
                        $cp->gps_accuracy_meters, $cp->distance_from_site_meters, $cp->within_geofence === null ? '' : ($cp->within_geofence ? 'yes' : 'no'),
                        $cp->matchedSite?->name, $cp->network_status, $cp->issue_reported, $cp->employee_explanation, $cp->hr_reason_label, $cp->hr_note,
                        $cp->escalated_at?->toDateTimeString(), $cp->reviewer?->name, $cp->reviewed_at?->toDateTimeString(),
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
            'instructionLibrary' => $settings->instructions(),
        ];
    }

    /** @return array{0: array, 1: array<int>} */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'project_site_id' => ['required', 'exists:sites,id'],
            'instruction' => ['required', 'string', 'max:200'],
            'reason' => ['nullable', 'string', 'max:1000'],
            'employees' => ['required', 'array', 'min:1'],
            'employees.*' => ['integer', 'exists:employees,id'],
            'response_window_minutes' => ['required', 'integer', 'between:3,120'],
        ], [
            'employees.required' => 'Select at least one employee to include.',
            'instruction.required' => 'Enter the checkpoint instruction employees must follow.',
        ]);

        $employees = array_map('intval', $data['employees']);
        unset($data['employees']);

        return [$data, $employees];
    }
}
