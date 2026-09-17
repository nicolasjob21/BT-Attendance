<?php

namespace App\Services\Checkpoint;

use App\Models\Checkpoint;
use App\Models\CheckpointCampaign;
use App\Models\User;
use App\Notifications\CheckpointActivated;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Campaign lifecycle: draft → active → expired → completed (or paused /
 * cancelled). Activation stamps ONE official start and ONE deadline for
 * every selected employee. Every transition is audited; evidence is kept.
 */
class CampaignManager
{
    public function __construct(private CheckpointAudit $audit) {}

    /** Create a draft with its participants. */
    public function create(array $attributes, array $employeeIds, User $by): CheckpointCampaign
    {
        return DB::transaction(function () use ($attributes, $employeeIds, $by) {
            $campaign = CheckpointCampaign::create($attributes + [
                'status' => CheckpointCampaign::DRAFT,
                'created_by' => $by->id,
            ]);
            $campaign->employees()->sync(array_values(array_unique($employeeIds)));
            $this->audit->campaign($campaign, 'created', $by, ['participants' => count($employeeIds)]);

            return $campaign;
        });
    }

    public function update(CheckpointCampaign $campaign, array $attributes, array $employeeIds, User $by): CheckpointCampaign
    {
        abort_unless($campaign->isDraft(), 422, 'Only a draft checkpoint can be edited.');

        return DB::transaction(function () use ($campaign, $attributes, $employeeIds, $by) {
            $campaign->update($attributes);
            $campaign->employees()->sync(array_values(array_unique($employeeIds)));
            $this->audit->campaign($campaign, 'updated', $by, ['participants' => count($employeeIds)]);

            return $campaign;
        });
    }

    /**
     * Activate now: the server sets starts_at = now and expires_at = now +
     * window, creates one response row per employee and notifies them all.
     * $by is null when the dispatcher starts a scheduled checkpoint.
     */
    public function activate(CheckpointCampaign $campaign, ?User $by, ?Carbon $now = null): CheckpointCampaign
    {
        $now ??= Carbon::now();
        abort_unless($campaign->isDraft(), 422, 'This checkpoint cannot be activated from its current status.');

        if ($campaign->participants()->count() === 0) {
            throw ValidationException::withMessages(['employees' => 'Add at least one employee before activating.']);
        }

        DB::transaction(function () use ($campaign, $by, $now) {
            $starts = $now->copy()->startOfSecond();
            $expires = $starts->copy()->addMinutes($campaign->response_window_minutes);

            $campaign->update([
                'status' => CheckpointCampaign::ACTIVE,
                'starts_at' => $starts,
                'expires_at' => $expires,
                'scheduled_start_at' => null,
                'activated_by' => $by?->id,
                'activated_at' => $now,
            ]);

            $this->audit->campaign($campaign, $by ? 'activated' : 'auto_started', $by, [
                'starts_at' => $starts->toDateTimeString(),
                'expires_at' => $expires->toDateTimeString(),
            ]);

            // One response row per employee — same start, same deadline.
            $campaign->load('employees.user');
            foreach ($campaign->employees as $employee) {
                $cp = Checkpoint::firstOrCreate(
                    ['campaign_id' => $campaign->id, 'employee_id' => $employee->id],
                    ['project_site_id' => $campaign->project_site_id, 'status' => Checkpoint::PENDING],
                );
                if ($employee->user) {
                    $employee->user->notify(new CheckpointActivated($cp));
                    $cp->update(['status' => Checkpoint::NOTIFIED, 'notified_at' => $now]);
                }
            }
        });

        return $campaign->refresh();
    }

    /** Plan a start time; the dispatcher activates it at that moment. */
    public function schedule(CheckpointCampaign $campaign, Carbon $at, User $by): CheckpointCampaign
    {
        abort_unless($campaign->isDraft(), 422, 'Only a draft checkpoint can be scheduled.');
        if ($at->lte(Carbon::now())) {
            throw ValidationException::withMessages(['scheduled_start_at' => 'The start time must be in the future — or activate now.']);
        }
        if ($campaign->participants()->count() === 0) {
            throw ValidationException::withMessages(['employees' => 'Add at least one employee before scheduling.']);
        }

        $campaign->update(['scheduled_start_at' => $at, 'schedule_mode' => 'manual', 'random_window_start' => null, 'random_window_end' => null]);
        $this->audit->campaign($campaign, 'scheduled', $by, ['scheduled_start_at' => $at->toDateTimeString(), 'mode' => 'manual']);

        return $campaign;
    }

    /**
     * Let the system pick: draw the start time at random (CSPRNG) inside the
     * admin's window on the given date, leaving room for the response window
     * before the window ends. The admin sees the drawn time on the campaign
     * page so they can give the team leader a heads-up; employees do not.
     */
    public function scheduleRandom(CheckpointCampaign $campaign, Carbon $date, string $from, string $to, User $by): CheckpointCampaign
    {
        abort_unless($campaign->isDraft(), 422, 'Only a draft checkpoint can be scheduled.');
        if ($campaign->participants()->count() === 0) {
            throw ValidationException::withMessages(['employees' => 'Add at least one employee before scheduling.']);
        }

        $windowStart = $date->copy()->setTimeFromTimeString($from);
        $windowEnd = $date->copy()->setTimeFromTimeString($to);

        // Never in the past, and the whole response window must fit before the window closes.
        $earliest = $windowStart->max(Carbon::now()->addMinute())->startOfMinute();
        $latest = $windowEnd->copy()->subMinutes($campaign->response_window_minutes)->startOfMinute();

        if ($latest->lt($earliest)) {
            throw ValidationException::withMessages([
                'random_window' => 'That window is too short (or already over) for a '.$campaign->response_window_minutes.'-minute checkpoint. Widen it or pick a later time.',
            ]);
        }

        $minutes = (int) $earliest->diffInMinutes($latest);
        $at = $earliest->copy()->addMinutes(random_int(0, $minutes));

        $campaign->update([
            'scheduled_start_at' => $at,
            'schedule_mode' => 'random',
            'random_window_start' => $windowStart->format('H:i:s'),
            'random_window_end' => $windowEnd->format('H:i:s'),
        ]);
        $this->audit->campaign($campaign, 'scheduled', $by, [
            'scheduled_start_at' => $at->toDateTimeString(), 'mode' => 'random',
            'window' => $windowStart->format('H:i').'–'.$windowEnd->format('H:i'),
        ]);

        return $campaign;
    }

    /**
     * Freeze the shared countdown. While paused no submission is accepted;
     * the deadline is extended by the paused duration on resume so every
     * employee still gets the full window (recorded in the audit log).
     */
    public function pause(CheckpointCampaign $campaign, User $by, ?string $reason = null): CheckpointCampaign
    {
        abort_unless($campaign->isActive(), 422, 'Only an active checkpoint can be paused.');

        $campaign->update(['status' => CheckpointCampaign::PAUSED, 'paused_by' => $by->id, 'paused_at' => now()]);
        $this->audit->campaign($campaign, 'paused', $by, array_filter(['reason' => $reason, 'deadline_at_pause' => $campaign->expires_at?->toDateTimeString()]));

        return $campaign;
    }

    public function resume(CheckpointCampaign $campaign, User $by, ?Carbon $now = null): CheckpointCampaign
    {
        $now ??= Carbon::now();
        abort_unless($campaign->status === CheckpointCampaign::PAUSED, 422, 'Only a paused checkpoint can be resumed.');

        $pausedFor = (int) $campaign->paused_at->diffInSeconds($now);
        $newDeadline = $campaign->expires_at->copy()->addSeconds($pausedFor);

        $campaign->update([
            'status' => CheckpointCampaign::ACTIVE,
            'expires_at' => $newDeadline,
            'paused_by' => null,
            'paused_at' => null,
        ]);
        $this->audit->campaign($campaign, 'resumed', $by, [
            'paused_seconds' => $pausedFor,
            'new_deadline' => $newDeadline->toDateTimeString(),
        ]);

        return $campaign;
    }

    /** Cancel a draft, or abort a running checkpoint. Response rows are kept. */
    public function cancel(CheckpointCampaign $campaign, User $by, ?string $reason = null): CheckpointCampaign
    {
        abort_if($campaign->isFinished(), 422, 'This checkpoint is already finished.');

        DB::transaction(function () use ($campaign, $by, $reason) {
            $campaign->update([
                'status' => CheckpointCampaign::CANCELLED,
                'closed_by' => $by->id,
                'closed_at' => now(),
            ]);
            $this->audit->campaign($campaign, 'cancelled', $by, array_filter(['reason' => $reason]));
        });

        return $campaign;
    }

    /** HR signs off an expired checkpoint after follow-up. */
    public function complete(CheckpointCampaign $campaign, User $by): CheckpointCampaign
    {
        abort_unless($campaign->status === CheckpointCampaign::EXPIRED, 422, 'A checkpoint can be completed once it has expired.');

        $campaign->update(['status' => CheckpointCampaign::COMPLETED, 'closed_by' => $by->id, 'closed_at' => now()]);
        $this->audit->campaign($campaign, 'completed', $by, [
            'open_follow_ups' => $campaign->checkpoints()->nonCompliant()->whereNull('reviewed_at')->count(),
        ]);

        return $campaign;
    }

    /** End the window early: expire now (used when HR has what they need). */
    public function endNow(CheckpointCampaign $campaign, User $by, CheckpointDispatcher $dispatcher): CheckpointCampaign
    {
        abort_unless($campaign->isLive(), 422, 'Only a running checkpoint can be ended.');

        $campaign->update(['status' => CheckpointCampaign::ACTIVE, 'expires_at' => now(), 'paused_at' => null, 'paused_by' => null]);
        $this->audit->campaign($campaign, 'ended_early', $by);
        $dispatcher->expireCampaign($campaign->refresh(), now());

        return $campaign->refresh();
    }
}
