<?php

namespace App\Notifications;

use App\Models\Checkpoint;
use Illuminate\Notifications\Notification;

/**
 * Sent to every selected employee the moment HR activates the shared
 * checkpoint. Carries the official deadline set by the server.
 */
class CheckpointActivated extends Notification
{
    public function __construct(public Checkpoint $checkpoint) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $cp = $this->checkpoint->loadMissing(['campaign', 'site']);
        $deadline = $cp->campaign?->expires_at;

        return [
            'kind' => 'checkpoint',
            'title' => 'Live presence checkpoint active',
            'message' => 'Live presence checkpoint active. Please complete your verification before '
                .($deadline?->format('g:i A') ?? 'the deadline').'. '.($cp->site?->name ?? '').' — '.($cp->campaign?->instruction ?? ''),
            'site' => $cp->site?->name,
            'instruction' => $cp->campaign?->instruction,
            'starts_at' => $cp->campaign?->starts_at?->toIso8601String(),
            'expires_at' => $deadline?->toIso8601String(),
            'checkpoint_id' => $cp->id,
            'url' => route('my-checkpoints.show', $cp),
        ];
    }
}
