<?php

namespace App\Services\Checkpoint;

use App\Models\Checkpoint;
use App\Models\CheckpointCampaign;
use App\Models\User;
use App\Notifications\CheckpointExceptionFlagged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Server-side clock for the module. Runs every minute from the scheduler and
 * (throttled) from web requests so deadlines are enforced even without cron:
 *
 *  1. activate drafts whose planned start time has arrived,
 *  2. expire active checkpoints past their shared deadline and mark every
 *     employee without a valid submission as MISSED.
 */
class CheckpointDispatcher
{
    public function __construct(private CheckpointAudit $audit) {}

    /** @return array{started:int, expired:int, missed:int} */
    public function tick(?Carbon $now = null): array
    {
        $now ??= Carbon::now();

        return [
            'started' => $this->startScheduled($now),
        ] + $this->expireLapsed($now);
    }

    public function sweep(): void
    {
        $ttl = max(5, (int) config('checkpoints.sweep_throttle_seconds', 15));
        if (Cache::add('checkpoints:sweep', 1, $ttl)) {
            $this->tick();
        }
    }

    public function startScheduled(Carbon $now): int
    {
        $n = 0;
        CheckpointCampaign::status(CheckpointCampaign::DRAFT)
            ->whereNotNull('scheduled_start_at')
            ->where('scheduled_start_at', '<=', $now)
            ->each(function (CheckpointCampaign $c) use ($now, &$n) {
                app(CampaignManager::class)->activate($c, null, $now);
                $n++;
            });

        return $n;
    }

    /** @return array{expired:int, missed:int} */
    public function expireLapsed(Carbon $now): array
    {
        $expired = 0;
        $missed = 0;
        CheckpointCampaign::status(CheckpointCampaign::ACTIVE)
            ->where('expires_at', '<', $now)
            ->each(function (CheckpointCampaign $c) use ($now, &$expired, &$missed) {
                $missed += $this->expireCampaign($c, $now);
                $expired++;
            });

        return ['expired' => $expired, 'missed' => $missed];
    }

    /** Close the window: everyone without a valid response becomes MISSED. */
    public function expireCampaign(CheckpointCampaign $campaign, Carbon $now): int
    {
        $missed = 0;
        $campaign->checkpoints()->status(Checkpoint::WAITING_STATUSES)
            ->each(function (Checkpoint $cp) use ($campaign, $now, &$missed) {
                // A failed attempt (outside fence / no GPS) keeps its own
                // status so HR sees *why*; only silent employees become MISSED.
                if (in_array($cp->status, [Checkpoint::PENDING, Checkpoint::NOTIFIED], true)) {
                    $cp->update([
                        'status' => Checkpoint::MISSED,
                        'failure_reason' => 'no_response',
                        'validation_message' => 'No submission was received before the checkpoint deadline at '.$campaign->expires_at->format('g:i A').'.',
                        'server_timestamp' => $now,
                    ]);
                    $missed++;
                }
                $this->audit->checkpoint($cp, 'deadline_passed', null, ['status' => $cp->status]);
            });

        $campaign->update(['status' => CheckpointCampaign::EXPIRED]);
        $nonCompliant = $campaign->checkpoints()->nonCompliant()->count();
        $this->audit->campaign($campaign, 'expired', null, ['missed' => $missed, 'non_compliant' => $nonCompliant]);

        if ($nonCompliant > 0) {
            foreach (User::permission('review checkpoint exceptions')->get() as $reviewer) {
                $reviewer->notify(new CheckpointExceptionFlagged($campaign, $nonCompliant));
            }
        }

        return $missed;
    }
}
