<?php

namespace App\Services\Checkpoint;

use App\Models\Checkpoint;
use App\Models\CheckpointCampaign;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Campaign lifecycle: draft → scheduled/active → paused → completed/cancelled.
 * Every transition is audited. Checkpoint evidence is never deleted.
 */
class CampaignManager
{
    public function __construct(
        private CheckpointScheduler $scheduler,
        private CheckpointAudit $audit,
    ) {}

    /** Create a draft campaign with its participants. */
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

    /** Update a draft/scheduled campaign's configuration. */
    public function update(CheckpointCampaign $campaign, array $attributes, array $employeeIds, User $by): CheckpointCampaign
    {
        abort_unless($campaign->canEdit(), 422, 'Only draft or scheduled campaigns can be edited.');

        return DB::transaction(function () use ($campaign, $attributes, $employeeIds, $by) {
            $campaign->update($attributes);
            $campaign->employees()->sync(array_values(array_unique($employeeIds)));
            $this->audit->campaign($campaign, 'updated', $by, ['participants' => count($employeeIds)]);

            return $campaign;
        });
    }

    /**
     * Activate now (if today is inside the campaign dates) or schedule the
     * campaign to start automatically on its start date.
     */
    public function activate(CheckpointCampaign $campaign, User $by, ?Carbon $now = null): CheckpointCampaign
    {
        $now ??= Carbon::now();
        abort_unless($campaign->canActivate(), 422, 'This campaign cannot be activated from its current status.');

        if ($campaign->participants()->count() === 0) {
            throw ValidationException::withMessages(['employees' => 'Add at least one employee before activating.']);
        }
        if ($campaign->end_date->lt($now->copy()->startOfDay())) {
            throw ValidationException::withMessages(['end_date' => 'The campaign end date is already in the past.']);
        }

        $startsToday = $campaign->start_date->lte($now->copy()->startOfDay());

        DB::transaction(function () use ($campaign, $by, $now, $startsToday) {
            $campaign->update([
                'status' => $startsToday ? CheckpointCampaign::ACTIVE : CheckpointCampaign::SCHEDULED,
                'activated_by' => $by->id,
                'activated_at' => $now,
            ]);
            $this->audit->campaign($campaign, $startsToday ? 'activated' : 'scheduled', $by, [
                'start_date' => $campaign->start_date->toDateString(),
            ]);

            if ($startsToday) {
                // Generate the rest of today right away so the first random
                // checkpoint can land within minutes of activation.
                $this->scheduler->generateForDay($campaign, $now->copy()->startOfDay(), $now);
            }
        });

        return $campaign;
    }

    public function pause(CheckpointCampaign $campaign, User $by, ?string $reason = null): CheckpointCampaign
    {
        abort_unless($campaign->status === CheckpointCampaign::ACTIVE, 422, 'Only an active campaign can be paused.');

        $campaign->update(['status' => CheckpointCampaign::PAUSED, 'paused_by' => $by->id, 'paused_at' => now()]);
        $this->audit->campaign($campaign, 'paused', $by, array_filter(['reason' => $reason]));

        return $campaign;
    }

    public function resume(CheckpointCampaign $campaign, User $by, ?Carbon $now = null): CheckpointCampaign
    {
        $now ??= Carbon::now();
        abort_unless($campaign->status === CheckpointCampaign::PAUSED, 422, 'Only a paused campaign can be resumed.');

        DB::transaction(function () use ($campaign, $by, $now) {
            // Checkpoints whose time passed while paused were never asked —
            // drop them rather than opening a flood the moment we resume.
            $skipped = $campaign->checkpoints()->status(Checkpoint::SCHEDULED)
                ->where('scheduled_at', '<=', $now)
                ->update([
                    'verification_status' => Checkpoint::CANCELLED,
                    'failure_reason' => 'campaign_paused',
                    'validation_message' => 'Skipped: the campaign was paused at the scheduled time.',
                ]);
            $campaign->update(['status' => CheckpointCampaign::ACTIVE, 'paused_by' => null, 'paused_at' => null]);
            $this->audit->campaign($campaign, 'resumed', $by, ['skipped_checkpoints' => $skipped]);
        });

        return $campaign;
    }

    /** End an active/paused campaign early. Results are kept. */
    public function end(CheckpointCampaign $campaign, User $by, ?string $reason = null): CheckpointCampaign
    {
        abort_unless($campaign->isLive(), 422, 'Only an active or paused campaign can be ended.');

        DB::transaction(function () use ($campaign, $by, $reason) {
            $cancelled = $campaign->checkpoints()->status([Checkpoint::SCHEDULED, Checkpoint::OPEN])->update([
                'verification_status' => Checkpoint::CANCELLED,
                'failure_reason' => 'campaign_ended',
                'validation_message' => 'The campaign was ended before this checkpoint closed.',
            ]);
            $campaign->update([
                'status' => CheckpointCampaign::COMPLETED,
                'closed_by' => $by->id,
                'closed_at' => now(),
            ]);
            $this->audit->campaign($campaign, 'ended_early', $by, array_filter(['reason' => $reason, 'cancelled_checkpoints' => $cancelled]));
        });

        return $campaign;
    }

    /** Cancel a draft or scheduled campaign that never ran. */
    public function cancel(CheckpointCampaign $campaign, User $by, ?string $reason = null): CheckpointCampaign
    {
        abort_unless($campaign->canActivate(), 422, 'Only a draft or scheduled campaign can be cancelled; end a running one instead.');

        $campaign->update([
            'status' => CheckpointCampaign::CANCELLED,
            'closed_by' => $by->id,
            'closed_at' => now(),
        ]);
        $this->audit->campaign($campaign, 'cancelled', $by, array_filter(['reason' => $reason]));

        return $campaign;
    }

    /** Sign off a campaign that completed on its own (stamps closed_by/at). */
    public function close(CheckpointCampaign $campaign, User $by): CheckpointCampaign
    {
        abort_unless($campaign->status === CheckpointCampaign::COMPLETED && $campaign->closed_at === null, 422, 'This campaign is not awaiting closure.');

        $campaign->update(['closed_by' => $by->id, 'closed_at' => now()]);
        $this->audit->campaign($campaign, 'closed', $by);

        return $campaign;
    }
}
