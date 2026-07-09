<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/**
 * Sent to approvers (HR / CEO) when an employee files a request that needs review.
 */
class ApprovalRequested extends Notification
{
    public function __construct(
        public string $type,        // "Leave", "Overtime", "Early leave"
        public string $employee,    // employee full name
        public string $summary,     // short detail, e.g. "2h overtime on Jul 8"
        public string $url,         // where the approver reviews it
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'request',
            'title' => "New {$this->type} request",
            'message' => "{$this->employee} — {$this->summary}",
            'url' => $this->url,
        ];
    }
}
