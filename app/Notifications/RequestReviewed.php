<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/**
 * Sent to the employee when their request is approved or rejected/denied.
 */
class RequestReviewed extends Notification
{
    public function __construct(
        public string $type,        // "Leave", "Overtime", ...
        public string $status,      // "approved" | "denied" | "rejected"
        public string $url,         // where the employee can see it
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $approved = $this->status === 'approved';

        return [
            'kind' => $approved ? 'approved' : 'rejected',
            'title' => ucfirst($this->type) . ' ' . $this->status,
            'message' => "Your {$this->type} request was {$this->status}.",
            'url' => $this->url,
        ];
    }
}
