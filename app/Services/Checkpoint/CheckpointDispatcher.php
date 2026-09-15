<?php

namespace App\Services\Checkpoint;

use App\Models\Checkpoint;
use App\Models\CheckpointCampaign;
use App\Models\User;
use App\Notifications\CheckpointExceptionFlagged;
use App\Notifications\CheckpointOpened;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * The clock of the module. Runs every minute from the scheduler (and
 * opportunistically from a few web requests, throttled) to:
 *
 *  1. start scheduled campaigns whose first day has arrived,
 *  2. generate today's random checkpoints for active campaigns,
 *  3. open checkpoints whose secret time has come and notify the employee,
 *  4. mark open checkpoints that lapsed without a submission as missed,
 *  5. complete campaigns past their end date.
 */
class CheckpointDispatcher
{
    public function __construct(
        private CheckpointScheduler $scheduler,
        private CheckpointAudit $audit,
    ) {}

    /** @return array{started:int, generated:int, opened:int, missed:int, completed:int} */
    public function tick(?Carbon $now = null): array
    {
        $now ??= Carbon::now();

        return [
            'started' => $this->startScheduledCampaigns($now),
            'generated' => $this->generateToday($now),
            'opened' => $this->openDue($now),
            'missed' => $this->expireLapsed($now),
            'completed' => $this->completeFinished($now),
        ];
    }

    /**
     * Cheap, throttled tick for web requests so the module still behaves when
     * `schedule:run` is not wired up (local dev, small deployments).
     */
    public function sweep(): void
    {
        $ttl = max(5, (int) config('checkpoints.sweep_throttle_seconds', 30));
        if (Cache::add('checkpoints:sweep', 1, $ttl)) {
            $this->tick();
        }
    }

    public function startScheduledCampaigns(Carbon $now): int
    {
        $n = 0;
        CheckpointCampaign::status(CheckpointCampaign::SCHEDULED)
            ->whereDate('start_date', '<=', $now->toDateString())
            ->each(function (CheckpointCampaign $c) use ($now, &$n) {
                $c->update(['status' => CheckpointCampaign::ACTIVE, 'activated_at' => $c->activated_at ?? $now]);
                $this->audit->campaign($c, 'auto_started', null, ['at' => $now->toDateTimeString()]);
                $n++;
            });

        return $n;
    }

    public function generateToday(Carbon $now): int
    {
        $n = 0;
        $today = $now->copy()->startOfDay();
        CheckpointCampaign::status(CheckpointCampaign::ACTIVE)
            ->whereDate('start_date', '<=', $today->toDateString())
            ->whereDate('end_date', '>=', $today->toDateString())
            ->each(function (CheckpointCampaign $c) use ($today, $now, &$n) {
                // Only fill in from "now" so a campaign activated at 2 PM does
                // not get checkpoints that are already in the past.
                $n += $this->scheduler->generateForDay($c, $today, $now);
            });

        return $n;
    }

    public function openDue(Carbon $now): int
    {
        $n = 0;
        Checkpoint::status(Checkpoint::SCHEDULED)
            ->where('scheduled_at', '<=', $now)
            ->whereHas('campaign', fn ($q) => $q->where('status', CheckpointCampaign::ACTIVE))
            ->with(['campaign', 'employee.user', 'site'])
            ->each(function (Checkpoint $cp) use ($now, &$n) {
                $expires = $cp->scheduled_at->copy()->addMinutes($cp->campaign->response_window_minutes);
                if ($expires->lte($now)) {
                    // The scheduler was down for the whole window: nobody was
                    // asked, so this is not the employee's fault.
                    $cp->update([
                        'verification_status' => Checkpoint::CANCELLED,
                        'failure_reason' => 'no_response',
                        'validation_message' => 'Checkpoint could not be delivered in time (system was not running).',
                    ]);

                    return;
                }
                $cp->update([
                    'verification_status' => Checkpoint::OPEN,
                    'opened_at' => $now,
                    'expires_at' => $expires,
                ]);
                $cp->employee?->user?->notify(new CheckpointOpened($cp));
                $n++;
            });

        return $n;
    }

    public function expireLapsed(Carbon $now): int
    {
        $n = 0;
        Checkpoint::status(Checkpoint::OPEN)
            ->where('expires_at', '<', $now)
            ->with(['employee', 'site'])
            ->each(function (Checkpoint $cp) use ($now, &$n) {
                $cp->update([
                    'verification_status' => Checkpoint::MISSED,
                    'failure_reason' => 'no_response',
                    'validation_message' => 'No submission was received before the checkpoint expired at '.$cp->expires_at->format('g:i A').'.',
                    'review_status' => 'pending',
                    'server_timestamp' => $now,
                ]);
                $this->audit->checkpoint($cp, 'missed', null, ['expired_at' => $cp->expires_at->toDateTimeString()]);
                $this->notifyReviewers($cp);
                $n++;
            });

        return $n;
    }

    public function completeFinished(Carbon $now): int
    {
        $n = 0;
        CheckpointCampaign::status([CheckpointCampaign::ACTIVE, CheckpointCampaign::PAUSED])
            ->whereDate('end_date', '<', $now->toDateString())
            ->each(function (CheckpointCampaign $c) use ($now, &$n) {
                $c->update(['status' => CheckpointCampaign::COMPLETED]);
                $c->checkpoints()->status(Checkpoint::SCHEDULED)->update([
                    'verification_status' => Checkpoint::CANCELLED,
                    'failure_reason' => 'campaign_ended',
                ]);
                $this->audit->campaign($c, 'auto_completed', null, ['at' => $now->toDateTimeString()]);
                $n++;
            });

        return $n;
    }

    /** Tell everyone who reviews checkpoint exceptions about a new one. */
    public function notifyReviewers(Checkpoint $cp): void
    {
        $reviewers = User::permission('review checkpoint exceptions')
            ->where('id', '!=', $cp->employee?->user_id)
            ->get();
        foreach ($reviewers as $reviewer) {
            $reviewer->notify(new CheckpointExceptionFlagged($cp));
        }
    }
}
