<?php

namespace App\Notifications;

use App\Models\Checkpoint;
use Illuminate\Notifications\Notification;

/**
 * Sent to the employee the moment a random checkpoint opens. Only the open
 * checkpoint is revealed — never the rest of the day's schedule.
 */
class CheckpointOpened extends Notification
{
    public function __construct(public Checkpoint $checkpoint) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $cp = $this->checkpoint->loadMissing('site');
        $minutes = $cp->campaign?->response_window_minutes ?? (int) $cp->opened_at?->diffInMinutes($cp->expires_at);

        return [
            'kind' => 'checkpoint',
            'title' => 'Presence verification required',
            'message' => "Please complete the checkpoint at {$cp->site?->name} within {$minutes} minutes"
                .' (expires '.$cp->expires_at?->format('g:i A').').',
            'site' => $cp->site?->name,
            'expires_at' => $cp->expires_at?->toIso8601String(),
            'checkpoint_id' => $cp->id,
            'url' => route('my-checkpoints.show', $cp),
        ];
    }
}
