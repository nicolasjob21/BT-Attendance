<?php

namespace App\Notifications;

use App\Models\PayrollPeriod;
use Illuminate\Notifications\Notification;

/** Sent once, on the morning of pay day, to everyone who can run payroll. */
class PayrollDue extends Notification
{
    public function __construct(public PayrollPeriod $period) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'payroll',
            'title' => 'Pay day today — payroll due',
            'message' => $this->period->label().' has not been computed. Run payroll, review the lines, then release.',
            'url' => route('payroll.index', ['period' => $this->period->id]),
        ];
    }
}
