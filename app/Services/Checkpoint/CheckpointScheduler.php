<?php

namespace App\Services\Checkpoint;

use App\Models\Checkpoint;
use App\Models\CheckpointCampaign;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Generates unpredictable checkpoint times on the server.
 *
 * Every participant gets their own set of random times per working day, drawn
 * with the CSPRNG (random_int) so a schedule cannot be guessed from a seed or
 * shared between colleagues. Times respect the campaign's working window,
 * min/max spacing and leave room for the response window before close.
 */
class CheckpointScheduler
{
    /**
     * Whether the campaign's parameters allow N checkpoints in its window.
     * Returns a human-readable problem, or null when feasible.
     */
    public function validate(int $windowMinutes, int $perDay, int $minGap, int $maxGap, int $responseWindow): ?string
    {
        if ($minGap > $maxGap) {
            return 'Minimum interval cannot be greater than the maximum interval.';
        }
        $needed = ($perDay - 1) * $minGap + $responseWindow;
        if ($needed > $windowMinutes) {
            return "Not enough working time: {$perDay} checkpoint(s) at least {$minGap} min apart plus a {$responseWindow}-minute response window need {$needed} min, but the window is only {$windowMinutes} min.";
        }

        return null;
    }

    /**
     * Create the day's scheduled checkpoints for every participant that does
     * not have any yet. Returns the number of checkpoints created.
     *
     * @param  Carbon|null  $notBefore  Only generate times after this instant
     *                                  (used when a campaign is activated mid-day).
     */
    public function generateForDay(CheckpointCampaign $campaign, Carbon $day, ?Carbon $notBefore = null): int
    {
        if (! $campaign->coversDay($day)) {
            return 0;
        }

        [$windowStart, $windowEnd] = $campaign->windowOn($day);
        $earliest = $notBefore && $notBefore->gt($windowStart) ? $notBefore->copy()->addMinute()->startOfMinute() : $windowStart;
        $latestOpen = $windowEnd->copy()->subMinutes($campaign->response_window_minutes);

        if ($earliest->gte($latestOpen)) {
            return 0; // window already (almost) over
        }

        $instructions = array_values($campaign->photo_instructions ?: ['Capture the current work area.']);
        $participants = $campaign->participants()->pluck('employee_id');
        $existing = $campaign->checkpoints()->whereDate('scheduled_for', $day->toDateString())
            ->pluck('employee_id')->unique()->flip();

        $created = 0;
        DB::transaction(function () use ($campaign, $day, $earliest, $latestOpen, $instructions, $participants, $existing, &$created) {
            foreach ($participants as $employeeId) {
                if ($existing->has($employeeId)) {
                    continue;
                }
                $times = $this->randomTimes(
                    $earliest, $latestOpen,
                    $campaign->checkpoints_per_day,
                    $campaign->minimum_interval_minutes,
                    $campaign->maximum_interval_minutes,
                );
                foreach ($times as $at) {
                    Checkpoint::create([
                        'campaign_id' => $campaign->id,
                        'employee_id' => $employeeId,
                        'project_site_id' => $campaign->project_site_id,
                        'scheduled_for' => $day->toDateString(),
                        'scheduled_at' => $at,
                        'photo_instruction' => $instructions[random_int(0, count($instructions) - 1)],
                        'verification_status' => Checkpoint::SCHEDULED,
                    ]);
                    $created++;
                }
            }
        });

        return $created;
    }

    /**
     * Draw up to $count sorted random minutes in [$from, $to] with consecutive
     * gaps in [$minGap, $maxGap]. The first checkpoint lands within $maxGap of
     * the window start so it cannot be predicted to always be "early".
     *
     * @return list<Carbon>
     */
    public function randomTimes(Carbon $from, Carbon $to, int $count, int $minGap, int $maxGap): array
    {
        $total = (int) $from->diffInMinutes($to);
        if ($count < 1 || $total < 0) {
            return [];
        }

        // Shrink the count if the window is too small rather than failing —
        // a mid-day activation still gets whatever fits.
        while ($count > 1 && ($count - 1) * $minGap > $total) {
            $count--;
        }

        $reserved = ($count - 1) * $minGap; // minutes that must remain for later gaps
        $offset = random_int(0, min($maxGap, $total - $reserved));
        $times = [$from->copy()->addMinutes($offset)];
        $used = $offset;

        for ($i = 1; $i < $count; $i++) {
            $remainingGaps = $count - 1 - $i;
            $upper = min($maxGap, $total - $used - $remainingGaps * $minGap);
            $gap = random_int($minGap, max($minGap, $upper));
            $used += $gap;
            $times[] = $from->copy()->addMinutes($used);
        }

        return $times;
    }
}
