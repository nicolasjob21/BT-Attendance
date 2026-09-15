<?php

namespace App\Http\Controllers;

use App\Models\Checkpoint;
use App\Models\CheckpointCampaign;
use App\Models\Employee;
use App\Services\Checkpoint\CheckpointDispatcher;
use App\Services\Checkpoint\CheckpointPhoto;
use App\Services\Checkpoint\CheckpointReviewer;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/** Checkpoint results: activity table, detail view, exception review, photo. */
class CheckpointResultController extends Controller
{
    public function __construct(
        private CheckpointReviewer $reviewer,
        private CheckpointDispatcher $dispatcher,
    ) {}

    /** Filterable checkpoint activity across campaigns. */
    public function index(Request $request)
    {
        $this->dispatcher->sweep();

        $filters = [
            'campaign' => $request->integer('campaign') ?: null,
            'employee' => $request->integer('employee') ?: null,
            'status' => $request->input('status'),
            'review' => $request->input('review'), // pending | reviewed
            'from' => $request->input('from'),
            'to' => $request->input('to'),
        ];

        $checkpoints = Checkpoint::query()
            ->whereNotIn('verification_status', [Checkpoint::SCHEDULED])
            ->when($filters['campaign'], fn ($q, $v) => $q->where('campaign_id', $v))
            ->when($filters['employee'], fn ($q, $v) => $q->where('employee_id', $v))
            ->when($filters['status'] && isset(Checkpoint::STATUSES[$filters['status']]), fn ($q) => $q->where('verification_status', $filters['status']))
            ->when($filters['review'] === 'pending', fn ($q) => $q->pendingReview())
            ->when($filters['review'] === 'reviewed', fn ($q) => $q->where('review_status', 'reviewed'))
            ->when($filters['from'], fn ($q, $v) => $q->whereDate('scheduled_for', '>=', Carbon::parse($v)->toDateString()))
            ->when($filters['to'], fn ($q, $v) => $q->whereDate('scheduled_for', '<=', Carbon::parse($v)->toDateString()))
            ->with(['employee:id,first_name,last_name,employee_no', 'site:id,name', 'campaign:id,name', 'reviewer:id,name'])
            ->latest('scheduled_at')->paginate(25)->withQueryString();

        $campaigns = CheckpointCampaign::orderByDesc('id')->get(['id', 'name', 'status']);
        $employees = Employee::orderBy('first_name')->orderBy('last_name')->get(['id', 'first_name', 'last_name']);

        return view('checkpoints.results', compact('checkpoints', 'campaigns', 'employees', 'filters'));
    }

    /** One checkpoint: evidence, map, movement context, review form. */
    public function show(Checkpoint $checkpoint, CheckpointPhoto $photos)
    {
        $checkpoint->load(['campaign.site', 'employee.user:id,name', 'site', 'matchedSite:id,name', 'reviewer:id,name']);
        $context = $this->reviewer->movementContext($checkpoint);
        $audit = $checkpoint->auditLogs()->with('user:id,name')->get();

        return view('checkpoints.result', [
            'checkpoint' => $checkpoint,
            'context' => $context,
            'audit' => $audit,
            'hasPhoto' => $photos->exists($checkpoint),
        ]);
    }

    public function review(Request $request, Checkpoint $checkpoint)
    {
        $data = $request->validate([
            'review_result' => ['required', 'in:'.implode(',', array_keys(Checkpoint::REVIEW_RESULTS))],
            'review_remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        $this->reviewer->review($checkpoint, $request->user(), $data['review_result'], $data['review_remarks'] ?? null);

        return back()->with('status', 'Checkpoint '.$checkpoint->reference().' marked as reviewed: '.Checkpoint::REVIEW_RESULTS[$data['review_result']].'.');
    }

    public function remarks(Request $request, Checkpoint $checkpoint)
    {
        $data = $request->validate(['review_remarks' => ['required', 'string', 'max:1000']]);
        $this->reviewer->addRemarks($checkpoint, $request->user(), $data['review_remarks']);

        return back()->with('status', 'Remarks saved.');
    }

    /** Private photo: the owner or anyone who can view results. */
    public function photo(Request $request, Checkpoint $checkpoint, CheckpointPhoto $photos)
    {
        $user = $request->user();
        abort_unless($checkpoint->employee_id === $user->employee?->id || $user->can('view checkpoint results'), 403);

        return $photos->response($checkpoint);
    }
}
