<?php

namespace App\Services;

use App\Models\ContributionRate;
use App\Models\Employee;
use App\Models\PayrollItem;
use App\Models\PayrollPeriod;
use Carbon\CarbonPeriod;

/**
 * Turns an employee's attendance, approved overtime and leave into a
 * computed payroll line for a semi-monthly cutoff.
 *
 * NOTE: this is a first, transparent implementation. Withholding tax is left
 * at 0 (TRAIN-law brackets can be layered on later); contributions are computed
 * monthly and split in half per cutoff.
 */
class PayrollCalculator
{
    public function calculate(Employee $employee, PayrollPeriod $period): PayrollItem
    {
        $monthly = (float) $employee->monthly_salary;
        $daily = (float) $employee->daily_rate ?: ($monthly / 22);
        $hourly = $daily / 8;

        // Semi-monthly base pay is half the monthly salary.
        $basicPay = round($monthly / 2, 2);

        // ---- attendance: expected vs worked days (fixed-schedule staff only) ----
        $expectedDays = $this->workingDays($period);
        $workedDays = $employee->attendanceLogs()
            ->whereBetween('logged_at', [$period->period_start->startOfDay(), $period->period_end->endOfDay()])
            ->where('log_type', 'time_in')
            ->get()
            ->groupBy(fn ($log) => $log->logged_at->toDateString())
            ->count();

        // Full-day approved leave is excused (offsets absences). Half-day leave is
        // handled separately below, so it is excluded here.
        $approvedLeaveDays = (float) $employee->leaveRequests()
            ->where('status', 'approved')
            ->where('day_portion', 'full')
            ->whereBetween('date_from', [$period->period_start, $period->period_end])
            ->sum('days');

        $hasAttendance = $employee->attendanceLogs()
            ->whereBetween('logged_at', [$period->period_start->startOfDay(), $period->period_end->endOfDay()])
            ->exists();

        $absencesDeduction = 0;
        $isFlexible = optional($employee->schedule)->is_flexible;

        // Only deduct absences for fixed-schedule staff who actually have logs
        // in the period (avoids zeroing pay when no data has been captured yet).
        if (! $isFlexible && $hasAttendance) {
            $absentDays = max(0, $expectedDays - $workedDays - $approvedLeaveDays);
            $absencesDeduction = round($absentDays * $daily, 2);
        }

        // ---- half-day leave: each APPROVED half day is paid at half the daily
        // rate, i.e. withhold 0.5 × daily. Only HR-approved half days count;
        // pending/rejected ones have no salary effect. This applies to everyone,
        // and is independent of overtime (which is added separately below).
        $approvedHalfDays = (float) $employee->leaveRequests()
            ->where('status', 'approved')
            ->whereIn('day_portion', ['half_am', 'half_pm'])
            ->whereBetween('date_from', [$period->period_start, $period->period_end])
            ->sum('days'); // 0.5 per approved half day
        $halfDayDeduction = round($approvedHalfDays * $daily, 2);

        // ---- overtime: approved hours × hourly rate × premium multiplier ----
        $overtimePay = 0;
        $approvedOt = $employee->overtimeRequests()
            ->where('status', 'approved')
            ->whereBetween('ot_date', [$period->period_start, $period->period_end])
            ->get();
        foreach ($approvedOt as $ot) {
            $overtimePay += (float) $ot->hours * $hourly * $ot->multiplier();
        }
        $overtimePay = round($overtimePay, 2);

        // ---- government contributions (monthly, split per cutoff) ----
        $year = (int) $period->period_end->format('Y');
        $sss = $this->contribution('sss', $monthly, $year);
        $philhealth = $this->contribution('philhealth', $monthly, $year);
        $pagibig = min($this->contribution('pagibig', $monthly, $year), 200.0); // Pag-IBIG employee cap ₱200

        $grossPay = round($basicPay + $overtimePay, 2);
        $totalDeductions = round($absencesDeduction + $halfDayDeduction + $sss + $philhealth + $pagibig, 2);
        $netPay = round($grossPay - $totalDeductions, 2);

        return PayrollItem::updateOrCreate(
            ['employee_id' => $employee->id, 'payroll_period_id' => $period->id],
            [
                'basic_pay' => $basicPay,
                'overtime_pay' => $overtimePay,
                'night_diff_pay' => 0,
                'holiday_pay' => 0,
                'gross_pay' => $grossPay,
                'late_undertime_deduction' => 0,
                'absences_deduction' => $absencesDeduction,
                'half_day_deduction' => $halfDayDeduction,
                'sss_deduction' => $sss,
                'philhealth_deduction' => $philhealth,
                'pagibig_deduction' => $pagibig,
                'withholding_tax' => 0,
                'total_deductions' => $totalDeductions,
                'net_pay' => $netPay,
            ]
        );
    }

    /**
     * Employee-share contribution for a type, halved for the semi-monthly cutoff.
     */
    private function contribution(string $type, float $monthlySalary, int $year): float
    {
        $rate = ContributionRate::forSalary($type, $monthlySalary, $year);
        if (! $rate) {
            return 0.0;
        }

        // Base salary is clamped to the bracket's floor/ceiling.
        $base = max((float) $rate->min_salary, $monthlySalary);
        if ($rate->max_salary !== null) {
            $base = min((float) $rate->max_salary, $base);
        }

        $monthlyShare = $base * (float) $rate->employee_rate;

        return round($monthlyShare / 2, 2); // split across the two monthly cutoffs
    }

    /** Weekdays (Mon–Fri) within the period. */
    private function workingDays(PayrollPeriod $period): int
    {
        $count = 0;
        foreach (CarbonPeriod::create($period->period_start, $period->period_end) as $day) {
            if (! $day->isWeekend()) {
                $count++;
            }
        }

        return $count;
    }
}
