<?php

namespace App\Services\Payroll;

use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\PayrollGenerated;
use App\Services\PayrollCalculator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

/**
 * Runs payroll on its own once the Super Admin switches automation on.
 *
 * Every day the scheduler calls tick():
 *  1. makes sure the current and the next semi-monthly periods exist
 *     (1–15 and 16–end of month), so HR never has to create them by hand;
 *  2. when automation is on and a period has ended, generates the payroll for
 *     every active employee and notifies everyone who can run payroll to
 *     review it. Lines HR already adjusted by hand are left untouched.
 *
 * Settings (see PayrollSettingsController):
 *   payroll.auto_enabled          bool  — master switch
 *   payroll.pay_date_offset_days  int   — pay date = cutoff end + N days (default 5)
 *   payroll.generate_delay_days   int   — run N days after the cutoff ends (default 1),
 *                                         giving time for late punches and OT approvals
 */
class PayrollAutomation
{
    public function __construct(private PayrollCalculator $calculator) {}

    public static function enabled(): bool
    {
        return (bool) Setting::get('payroll.auto_enabled', false);
    }

    /** @return array{periods_created:int, generated:list<string>} */
    public function tick(?Carbon $now = null): array
    {
        $now ??= Carbon::now();
        $before = PayrollPeriod::count();

        $current = PayrollPeriod::ensureFor($now);
        $current->next();

        $generated = [];
        if (self::enabled()) {
            $delay = (int) Setting::get('payroll.generate_delay_days', 1);
            $due = PayrollPeriod::where('status', 'open')
                ->whereNull('generated_at')
                ->whereDate('period_end', '<=', $now->copy()->subDays($delay)->toDateString())
                ->orderBy('period_start')
                ->get();

            foreach ($due as $period) {
                $this->generate($period, 'auto');
                $generated[] = $period->label();
            }
        }

        return ['periods_created' => PayrollPeriod::count() - $before, 'generated' => $generated];
    }

    /**
     * Compute every active employee's line for the period. $by is 'auto' or a
     * user id. Returns the number of lines computed (adjusted lines are skipped
     * unless $force).
     */
    public function generate(PayrollPeriod $period, string $by, bool $force = false): int
    {
        $employees = Employee::where('status', 'active')->get();
        foreach ($employees as $employee) {
            $this->calculator->calculate($employee, $period, $force);
        }

        $period->update([
            'status' => 'processing',
            'generated_at' => now(),
            'generated_by' => $by,
        ]);

        if ($by === 'auto') {
            $reviewers = User::permission('run payroll')->get();
            Notification::send($reviewers, new PayrollGenerated($period, $employees->count()));
        }

        return $employees->count();
    }
}
