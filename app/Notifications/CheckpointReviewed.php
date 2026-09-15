<?php

namespace App\Notifications;

use App\Models\Checkpoint;
use Illuminate\Notifications\Notification;

/** Sent to the employee when HR approves or rejects their checkpoint exception. */
class CheckpointReviewed extends Notification
{
    public function __construct(public Checkpoint $checkpoint) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $cp = $this->checkpoint;
        $ok = $cp->status === Checkpoint::APPROVED_EXCEPTION;

        return [
            'kind' => $ok ? 'approved' : 'rejected',
            'title' => $ok ? 'Checkpoint exception approved' : 'Checkpoint exception rejected',
            'message' => "Your checkpoint {$cp->reference()} was ".($ok ? 'approved' : 'rejected')
                .($cp->hr_reason_label ? " ({$cp->hr_reason_label})" : '').($cp->hr_note ? " — “{$cp->hr_note}”" : '').'.',
            'url' => route('my-checkpoints.show', $cp),
        ];
    }
}
