<?php

namespace App\Notifications;

use App\Models\PayrollItem;
use Illuminate\Notifications\Notification;

/** Sent to each employee when the Super Admin releases the payroll for a period. */
class PayslipReleased extends Notification
{
    public function __construct(public PayrollItem $item) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $period = $this->item->payrollPeriod;
        $how = $this->item->employee?->paysByCard() ? 'credited to your card' : 'released in cash with your printed payslip';

        return [
            'kind' => 'payroll',
            'title' => 'Your salary has been released',
            'message' => "{$period->label()} — ₱".number_format((float) $this->item->net_pay, 2)." {$how}. Your payslip is ready.",
            'url' => route('payroll.show', $this->item),
        ];
    }
}
