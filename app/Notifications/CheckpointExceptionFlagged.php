<?php

namespace App\Notifications;

use App\Models\CheckpointCampaign;
use Illuminate\Notifications\Notification;

/** Sent to checkpoint reviewers when a checkpoint expires with non-compliant employees. */
class CheckpointExceptionFlagged extends Notification
{
    public function __construct(public CheckpointCampaign $campaign, public int $nonCompliant) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $c = $this->campaign->loadMissing('site');

        return [
            'kind' => 'request',
            'title' => 'Checkpoint expired — follow-up needed',
            'message' => "{$c->name} at {$c->site?->name}: {$this->nonCompliant} employee(s) did not complete the checkpoint by "
                .$c->expires_at?->format('g:i A').'.',
            'url' => route('checkpoints.show', $c),
        ];
    }
}
