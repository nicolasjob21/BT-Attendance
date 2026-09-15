<?php

namespace App\Notifications;

use App\Models\Checkpoint;
use Illuminate\Notifications\Notification;

/** Sent to the employee when HR records a decision on their checkpoint exception. */
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
        $ok = in_array($cp->review_result, ['valid_reason', 'approved_official_errand', 'gps_issue', 'device_or_network_issue', 'confirmed_attendance'], true);

        return [
            'kind' => $ok ? 'approved' : 'rejected',
            'title' => 'Checkpoint reviewed',
            'message' => "Your checkpoint {$cp->reference} was reviewed: {$cp->review_result_label}."
                .($cp->review_remarks ? " “{$cp->review_remarks}”" : ''),
            'url' => route('my-checkpoints.show', $cp),
        ];
    }
}
