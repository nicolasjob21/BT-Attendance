<?php

namespace App\Notifications;

use App\Models\PayrollPeriod;
use Illuminate\Notifications\Notification;

/** Sent to everyone who can run payroll when the automation has computed a period. */
class PayrollGenerated extends Notification
{
    public function __construct(public PayrollPeriod $period, public int $employees) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'payroll',
            'title' => 'Payroll generated automatically',
            'message' => "{$this->period->label()} — {$this->employees} employee(s). Review the lines, adjust if needed, then close the period.",
            'url' => route('payroll.index', ['period' => $this->period->id]),
        ];
    }
}
