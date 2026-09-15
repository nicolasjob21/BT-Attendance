<?php

namespace App\Services\Checkpoint;

use App\Models\AttendanceLog;
use App\Models\Checkpoint;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Notifications\CheckpointReviewed;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Exception review: HR classifies a missed/failed/pending checkpoint. Nothing
 * here touches pay or discipline — it only records a reviewable decision.
 */
class CheckpointReviewer
{
    public function __construct(private CheckpointAudit $audit) {}

    public function review(Checkpoint $checkpoint, User $reviewer, string $result, ?string $remarks): Checkpoint
    {
        abort_unless($checkpoint->isException(), 422, 'Only missed, failed, expired or pending checkpoints can be reviewed.');

        $checkpoint->update([
            'review_status' => 'reviewed',
            'review_result' => $result,
            'review_remarks' => $remarks,
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
        ]);
        $this->audit->checkpoint($checkpoint, 'reviewed', $reviewer, array_filter(['result' => $result, 'remarks' => $remarks]));
        $checkpoint->employee?->user?->notify(new CheckpointReviewed($checkpoint));

        return $checkpoint;
    }

    /** HR adds remarks without (yet) classifying the case. */
    public function addRemarks(Checkpoint $checkpoint, User $reviewer, string $remarks): Checkpoint
    {
        $checkpoint->update(['review_remarks' => $remarks]);
        $this->audit->checkpoint($checkpoint, 'remarks_added', $reviewer, ['remarks' => $remarks]);

        return $checkpoint;
    }

    /**
     * Movement context around the checkpoint so the reviewer can see whether
     * an absence was already approved: the day's approved leave (incl. an
     * early-leave "go home" request) and the employee's punches that day.
     *
     * @return array{approved_leave: ?LeaveRequest, punches: Collection<int, AttendanceLog>, clocked_out: bool, last_punch: ?AttendanceLog}
     */
    public function movementContext(Checkpoint $cp): array
    {
        $day = $cp->scheduled_for->copy();
        $at = $cp->opened_at ?? $cp->scheduled_at;

        $leave = LeaveRequest::query()
            ->where('employee_id', $cp->employee_id)
            ->where('status', 'approved')
            ->whereDate('date_from', '<=', $day->toDateString())
            ->whereDate('date_to', '>=', $day->toDateString())
            ->with('leaveType:id,name,code')
            ->get()
            ->first(function (LeaveRequest $l) use ($at, $day) {
                if ($l->is_early_leave && $l->requested_time_out) {
                    // Early leave only excuses the time after the requested out.
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
            ->whereBetween('logged_at', [$day->copy()->startOfDay(), $day->copy()->endOfDay()])
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
}
