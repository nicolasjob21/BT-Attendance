<?php

namespace App\Notifications;

use App\Models\Checkpoint;
use Illuminate\Notifications\Notification;

/** Sent to checkpoint reviewers when a checkpoint is missed, failed, or needs review. */
class CheckpointExceptionFlagged extends Notification
{
    public function __construct(public Checkpoint $checkpoint) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $cp = $this->checkpoint->loadMissing(['employee', 'site']);
        $name = $cp->employee?->full_name ?? 'An employee';

        return [
            'kind' => 'request',
            'title' => 'Checkpoint needs review',
            'message' => "{$name} — {$cp->status_label} at {$cp->site?->name} ({$cp->reference})"
                .($cp->result_label ? ': '.$cp->result_label : '').'.',
            'url' => route('checkpoints.results.show', $cp),
        ];
    }
}
