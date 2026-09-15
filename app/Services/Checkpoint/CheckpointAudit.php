<?php

namespace App\Services\Checkpoint;

use App\Models\Checkpoint;
use App\Models\CheckpointAuditLog;
use App\Models\CheckpointCampaign;
use App\Models\User;

/** Writes the append-only audit trail for campaign and review actions. */
class CheckpointAudit
{
    public function campaign(CheckpointCampaign $campaign, string $action, ?User $user = null, array $details = []): CheckpointAuditLog
    {
        return $this->write($action, $user, $details, campaignId: $campaign->id);
    }

    public function checkpoint(Checkpoint $checkpoint, string $action, ?User $user = null, array $details = []): CheckpointAuditLog
    {
        return $this->write($action, $user, $details, campaignId: $checkpoint->campaign_id, checkpointId: $checkpoint->id);
    }

    private function write(string $action, ?User $user, array $details, ?int $campaignId = null, ?int $checkpointId = null): CheckpointAuditLog
    {
        return CheckpointAuditLog::create([
            'campaign_id' => $campaignId,
            'checkpoint_id' => $checkpointId,
            'user_id' => $user?->id,
            'action' => $action,
            'details' => $details ?: null,
            'ip_address' => app()->runningInConsole() ? null : request()->ip(),
        ]);
    }
}
