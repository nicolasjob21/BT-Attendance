<?php

namespace App\Services\Checkpoint;

use App\Models\AttendanceLog;
use App\Models\Checkpoint;
use App\Models\CheckpointReview;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Notifications\CheckpointReviewed;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * HR follow-up on a non-compliant response. Every action is stored as a
 * separate review record and audited; the original evidence and the
 * campaign's start/deadline are never modified.
 */
class CheckpointReviewer
{
    public function __construct(private CheckpointAudit $audit) {}

    /** Record the employee's explanation (as told to HR) and the HR reason. */
    public function recordExplanation(Checkpoint $cp, User $by, string $explanation, ?string $reason, ?string $note = null): Checkpoint
    {
        return $this->act($cp, $by, 'explanation_recorded', [
            'explanation' => $explanation, 'reason' => $reason, 'note' => $note,
        ], [
            'employee_explanation' => $explanation,
            'hr_reason' => $reason ?? $cp->hr_reason,
            'hr_note' => $note ?: $cp->hr_note,
        ]);
    }

    public function addNote(Checkpoint $cp, User $by, string $note): Checkpoint
    {
        return $this->act($cp, $by, 'note_added', ['note' => $note], ['hr_note' => $note]);
    }

    public function markForReview(Checkpoint $cp, User $by, ?string $note = null): Checkpoint
    {
        abort_unless($cp->isReviewable(), 422, 'A completed checkpoint has nothing to review.');

        return $this->act($cp, $by, 'marked_for_review', ['note' => $note], [
            'status' => Checkpoint::PENDING_REVIEW,
            'hr_note' => $note ?: $cp->hr_note,
        ]);
    }

    /** Approve: counts as completed after review. Stored as a review record. */
    public function approve(Checkpoint $cp, User $by, string $reason, ?string $note = null): Checkpoint
    {
        abort_unless($cp->isReviewable(), 422, 'A completed checkpoint has nothing to approve.');

        $cp = $this->act($cp, $by, 'approved', ['reason' => $reason, 'note' => $note], [
            'status' => Checkpoint::APPROVED_EXCEPTION,
            'verification_result' => Checkpoint::COMPLETED_AFTER_REVIEW,
            'hr_reason' => $reason,
            'hr_note' => $note ?: $cp->hr_note,
            'reviewed_by' => $by->id,
            'reviewed_at' => now(),
        ]);
        $cp->employee?->user?->notify(new CheckpointReviewed($cp));

        return $cp;
    }

    public function reject(Checkpoint $cp, User $by, string $reason, ?string $note = null): Checkpoint
    {
        abort_unless($cp->isReviewable(), 422, 'A completed checkpoint has nothing to reject.');

        $cp = $this->act($cp, $by, 'rejected', ['reason' => $reason, 'note' => $note], [
            'status' => Checkpoint::REJECTED_EXCEPTION,
            'verification_result' => null,
            'hr_reason' => $reason,
            'hr_note' => $note ?: $cp->hr_note,
            'reviewed_by' => $by->id,
            'reviewed_at' => now(),
        ]);
        $cp->employee?->user?->notify(new CheckpointReviewed($cp));

        return $cp;
    }

    public function escalate(Checkpoint $cp, User $by, ?string $note = null): Checkpoint
    {
        abort_unless($cp->isReviewable(), 422, 'A completed checkpoint cannot be escalated.');

        return $this->act($cp, $by, 'escalated', ['note' => $note], [
            'escalated_at' => now(),
            'hr_note' => $note ?: $cp->hr_note,
        ]);
    }

    /**
     * Movement context around the checkpoint: the day's approved leave (incl.
     * early-leave) and the employee's punches, so an already-approved absence
     * is visible to the reviewer.
     *
     * @return array{approved_leave: ?LeaveRequest, punches: Collection<int, AttendanceLog>, clocked_out: bool, last_punch: ?AttendanceLog}
     */
    public function movementContext(Checkpoint $cp): array
    {
        $at = $cp->campaign?->starts_at ?? $cp->created_at;
        $day = $at->copy()->startOfDay();

        $leave = LeaveRequest::query()
            ->where('employee_id', $cp->employee_id)
            ->where('status', 'approved')
            ->whereDate('date_from', '<=', $day->toDateString())
            ->whereDate('date_to', '>=', $day->toDateString())
            ->with('leaveType:id,name,code')
            ->get()
            ->first(function (LeaveRequest $l) use ($at, $day) {
                if ($l->is_early_leave && $l->requested_time_out) {
                    return $at->gte(Carbon::parse($day->toDateString().' '.$l->requested_time_out));
                }
                if ($l->day_portion === 'half_am') {
                    return $at->lt($day->copy()->setTime(12, 0));
                }
                if ($l->day_portion === 'half_pm') {
                    return $at->gte($day->copy()->setTime(12, 0));
                }

                return true;
            });

        $punches = AttendanceLog::query()
            ->where('employee_id', $cp->employee_id)
            ->whereBetween('logged_at', [$day, $day->copy()->endOfDay()])
            ->with('site:id,name')
            ->orderBy('logged_at')->get();

        $lastBefore = $punches->last(fn (AttendanceLog $l) => $l->logged_at->lte($at));

        return [
            'approved_leave' => $leave,
            'punches' => $punches,
            'clocked_out' => $lastBefore !== null && $lastBefore->log_type === 'time_out',
            'last_punch' => $lastBefore,
        ];
    }

    private function act(Checkpoint $cp, User $by, string $action, array $review, array $attributes): Checkpoint
    {
        return DB::transaction(function () use ($cp, $by, $action, $review, $attributes) {
            CheckpointReview::create([
                'checkpoint_id' => $cp->id,
                'reviewer_id' => $by->id,
                'action' => $action,
            ] + array_filter($review, fn ($v) => $v !== null && $v !== ''));

            $cp->update($attributes);
            $this->audit->checkpoint($cp, $action, $by, array_filter($review, fn ($v) => $v !== null && $v !== ''));

            return $cp->refresh();
        });
    }
}
