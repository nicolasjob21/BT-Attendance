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

/** Per-employee responses: cross-campaign list, detail view, HR follow-up actions, photo. */
class CheckpointResultController extends Controller
{
    public function __construct(
        private CheckpointReviewer $reviewer,
        private CheckpointDispatcher $dispatcher,
    ) {}

    public function index(Request $request)
    {
        $this->dispatcher->sweep();

        $filters = [
            'campaign' => $request->integer('campaign') ?: null,
            'employee' => $request->integer('employee') ?: null,
            'status' => $request->input('status'),
            'follow' => $request->input('follow'), // open | reviewed | escalated
            'from' => $request->input('from'),
            'to' => $request->input('to'),
        ];

        $checkpoints = Checkpoint::query()
            ->when($filters['campaign'], fn ($q, $v) => $q->where('campaign_id', $v))
            ->when($filters['employee'], fn ($q, $v) => $q->where('employee_id', $v))
            ->when($filters['status'] && isset(Checkpoint::STATUSES[$filters['status']]), fn ($q) => $q->where('status', $filters['status']))
            ->when($filters['follow'] === 'open', fn ($q) => $q->nonCompliant()->whereNull('reviewed_at'))
            ->when($filters['follow'] === 'reviewed', fn ($q) => $q->whereNotNull('reviewed_at'))
            ->when($filters['follow'] === 'escalated', fn ($q) => $q->whereNotNull('escalated_at'))
            ->when($filters['from'], fn ($q, $v) => $q->whereHas('campaign', fn ($c) => $c->whereDate('starts_at', '>=', Carbon::parse($v)->toDateString())))
            ->when($filters['to'], fn ($q, $v) => $q->whereHas('campaign', fn ($c) => $c->whereDate('starts_at', '<=', Carbon::parse($v)->toDateString())))
            ->with(['employee:id,first_name,last_name,employee_no', 'site:id,name', 'campaign:id,name,starts_at,expires_at', 'reviewer:id,name'])
            ->latest('updated_at')->paginate(25)->withQueryString();

        $campaigns = CheckpointCampaign::orderByDesc('id')->get(['id', 'name', 'status']);
        $employees = Employee::orderBy('first_name')->orderBy('last_name')->get(['id', 'first_name', 'last_name']);

        return view('checkpoints.results', compact('checkpoints', 'campaigns', 'employees', 'filters'));
    }

    public function show(Checkpoint $checkpoint, CheckpointPhoto $photos)
    {
        $checkpoint->load(['campaign.site', 'employee.user:id,name', 'employee.activeAssignment.site:id,name', 'site', 'matchedSite:id,name', 'reviewer:id,name', 'reviews.reviewer:id,name']);

        return view('checkpoints.result', [
            'checkpoint' => $checkpoint,
            'context' => $this->reviewer->movementContext($checkpoint),
            'audit' => $checkpoint->auditLogs()->with('user:id,name')->get(),
            'hasPhoto' => $photos->exists($checkpoint),
        ]);
    }

    /** One endpoint for the HR follow-up actions (explanation / note / review / approve / reject / escalate). */
    public function followUp(Request $request, Checkpoint $checkpoint)
    {
        $data = $request->validate([
            'action' => ['required', 'in:explanation,note,mark_review,approve,reject,escalate'],
            'explanation' => ['nullable', 'string', 'max:1000', 'required_if:action,explanation'],
            'reason' => ['nullable', 'in:'.implode(',', array_keys(Checkpoint::HR_REASONS)), 'required_if:action,approve,reject'],
            'note' => ['nullable', 'string', 'max:1000', 'required_if:action,note'],
        ], [
            'reason.required_if' => 'Select a reason before approving or rejecting.',
            'explanation.required_if' => 'Enter the employee\'s explanation.',
            'note.required_if' => 'Enter a note.',
        ]);

        $user = $request->user();
        $note = $data['note'] ?? null;
        $reason = $data['reason'] ?? null;

        switch ($data['action']) {
            case 'explanation':
                $this->reviewer->recordExplanation($checkpoint, $user, $data['explanation'], $reason, $note);
                $message = 'Explanation recorded.';
                break;
            case 'note':
                $this->reviewer->addNote($checkpoint, $user, $note);
                $message = 'HR note added.';
                break;
            case 'mark_review':
                $this->reviewer->markForReview($checkpoint, $user, $note);
                $message = 'Marked for HR review.';
                break;
            case 'approve':
                $this->reviewer->approve($checkpoint, $user, $reason, $note);
                $message = 'Exception approved — counts as completed after review.';
                break;
            case 'reject':
                $this->reviewer->reject($checkpoint, $user, $reason, $note);
                $message = 'Exception rejected.';
                break;
            default:
                $this->reviewer->escalate($checkpoint, $user, $note);
                $message = 'Case escalated to management.';
        }

        return back()->with('status', $checkpoint->reference().': '.$message);
    }

    /** Private photo: the owner or anyone who can view results. */
    public function photo(Request $request, Checkpoint $checkpoint, CheckpointPhoto $photos)
    {
        $user = $request->user();
        abort_unless($checkpoint->employee_id === $user->employee?->id || $user->can('view checkpoint results'), 403);

        return $photos->response($checkpoint);
    }
}
