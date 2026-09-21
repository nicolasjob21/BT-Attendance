<?php

namespace App\Services;

use App\Models\ContributionRate;
use App\Models\Employee;
use App\Models\PayrollDeduction;
use App\Models\PayrollItem;
use App\Models\PayrollPeriod;
use App\Services\Payroll\PayrollRates;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\DB;

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
    public function calculate(Employee $employee, PayrollPeriod $period, bool $force = false): PayrollItem
    {
        // A line HR edited by hand is never silently overwritten by a (re)run.
        $existing = PayrollItem::where('employee_id', $employee->id)->where('payroll_period_id', $period->id)->first();
        if ($existing && $existing->isAdjusted() && ! $force) {
            return $existing;
        }

        $monthly = (float) $employee->monthly_salary;
        $daily = (float) $employee->daily_rate ?: ($monthly / max(1, PayrollRates::get('working_days_per_month')));
        $hourly = $daily / max(1, PayrollRates::get('hours_per_day'));

        // Base pay per cutoff is a percentage of the monthly salary (50% = semi-monthly).
        $basicPay = round($monthly * PayrollRates::get('basic_cutoff_percent') / 100, 2);

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

        // Everyone is on the fixed office shift. Only deduct absences when the
        // employee actually has logs in the period (avoids zeroing pay when no
        // data has been captured yet).
        if ($hasAttendance) {
            $absentDays = max(0, $expectedDays - $workedDays - $approvedLeaveDays);
            $absencesDeduction = round($absentDays * $daily, 2);
        }

        // ---- late / undertime: office hours are fixed (8:30 – 5:30). Arriving
        // early never earns an early out: minutes in after time-in + grace are
        // late, minutes out before time-out are undertime, each deducted at the
        // per-minute rate. An approved early-leave or half-day for that date
        // excuses the undertime; late is only excused by a full-day leave.
        $lateUndertime = $hasAttendance ? $this->lateUndertimeDeduction($employee, $period, $hourly) : 0.0;

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

        // ---- overtime: pre-approved requests × hourly rate × premium multiplier ----
        // Paid ONE CUTOFF IN ARREARS: this payroll pays the OT worked in the
        // previous cutoff (1–15 → paid on the 16–end run; 16–end → paid on the
        // next month's 1–15 run), so the hours are final by the time payroll
        // is generated. Only hours actually worked (derived from attendance)
        // are paid, capped at what was approved in advance; requests without
        // derived hours yet (no clock-out) contribute nothing.
        $overtimePay = 0;
        [$otFrom, $otTo] = $period->overtimeWindow();
        $approvedOt = $employee->overtimeRequests()
            ->where('status', 'approved')
            ->whereNotNull('hours')
            ->whereBetween('ot_date', [$otFrom->toDateString(), $otTo->toDateString()])
            ->get();
        foreach ($approvedOt as $ot) {
            $overtimePay += ($ot->payableHours() ?? 0) * $hourly * $ot->multiplier();
        }
        $overtimePay = round($overtimePay, 2);

        // ---- government contributions (monthly amount, split 50/50 per cutoff) ----
        // 50% is deducted on the first-half (15th) payroll and the remaining
        // 50% on the second-half (end-of-month) payroll.
        $year = (int) $period->period_end->format('Y');
        $cutoff = $period->cutoff_type;
        $sss = $this->contribution('sss', $monthly, $year, $cutoff);
        $philhealth = $this->contribution('philhealth', $monthly, $year, $cutoff);
        $pagibig = $this->contribution('pagibig', $monthly, $year, $cutoff);

        // Allowance: the company-wide % of basic pay, or whatever HR typed on the line.
        $allowancePct = PayrollRates::get('allowance_percent');
        $allowances = $allowancePct > 0 ? round($basicPay * $allowancePct / 100, 2) : (float) ($existing?->allowances ?? 0);
        $otherDeductions = (float) ($existing?->other_deductions ?? 0);

        $grossPay = round($basicPay + $overtimePay + $allowances, 2);

        // Withholding tax: flat % of pay after government contributions.
        $taxable = max(0, $grossPay - $sss - $philhealth - $pagibig);
        $withholdingTax = round($taxable * PayrollRates::get('withholding_tax_percent') / 100, 2);

        return DB::transaction(function () use ($employee, $period, $existing, $allowances, $otherDeductions, $basicPay, $overtimePay, $grossPay, $lateUndertime, $absencesDeduction, $halfDayDeduction, $sss, $philhealth, $pagibig, $withholdingTax) {
            // ---- loans & missing items: give back what this line took last time
            // (a recalculation must never charge twice), then take this cutoff's
            // installment from every balance that is due.
            if ($existing) {
                $this->reverseDeductionPayments($existing);
            }

            $item = PayrollItem::updateOrCreate(
                ['employee_id' => $employee->id, 'payroll_period_id' => $period->id],
                [
                    'adjusted_at' => null,
                    'adjusted_by' => null,
                    'allowances' => $allowances,
                    'other_deductions' => $otherDeductions,
                    'basic_pay' => $basicPay,
                    'overtime_pay' => $overtimePay,
                    'night_diff_pay' => 0,
                    'holiday_pay' => 0,
                    'gross_pay' => $grossPay,
                    'late_undertime_deduction' => $lateUndertime,
                    'absences_deduction' => $absencesDeduction,
                    'half_day_deduction' => $halfDayDeduction,
                    'sss_deduction' => $sss,
                    'philhealth_deduction' => $philhealth,
                    'pagibig_deduction' => $pagibig,
                    'withholding_tax' => $withholdingTax,
                    'loan_deduction' => 0,
                    'missing_item_deduction' => 0,
                ]
            );

            [$loan, $missing] = $this->collectDeductions($item, $employee, $period);
            $item->loan_deduction = $loan;
            $item->missing_item_deduction = $missing;
            $item->recomputeTotals()->save();

            return $item;
        });
    }

    /** Undo the loan / missing-item installments a payroll line took, restoring each balance. */
    public function reverseDeductionPayments(PayrollItem $item): void
    {
        foreach ($item->deductionPayments()->with('deduction')->get() as $payment) {
            $payment->deduction?->refund((float) $payment->amount);
            $payment->delete();
        }
    }

    /**
     * Take this cutoff's installment from every active loan / missing-item
     * balance due for the employee. Returns [loan total, missing-item total].
     *
     * @return array{0: float, 1: float}
     */
    private function collectDeductions(PayrollItem $item, Employee $employee, PayrollPeriod $period): array
    {
        $loan = 0.0;
        $missing = 0.0;

        $due = PayrollDeduction::where('employee_id', $employee->id)->dueFor($period)->orderBy('starts_on')->orderBy('id')->get();
        foreach ($due as $deduction) {
            $amount = $deduction->nextInstallment();
            if ($amount <= 0) {
                continue;
            }
            $item->deductionPayments()->create([
                'payroll_deduction_id' => $deduction->id,
                'payroll_period_id' => $period->id,
                'amount' => $amount,
            ]);
            $deduction->collect($amount);

            if ($deduction->isLoan()) {
                $loan += $amount;
            } else {
                $missing += $amount;
            }
        }

        return [round($loan, 2), round($missing, 2)];
    }

    /**
     * Employee-share contribution for a type, split equally across the two
     * semi-monthly cutoffs: 50% on the first-half (15th) payroll and the
     * remaining 50% on the second-half (end-of-month) payroll.
     */
    private function contribution(string $type, float $monthlySalary, int $year, string $cutoffType): float
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

        // Pag-IBIG employee share is capped at ₱200 per MONTH. Cap before the
        // split so each cutoff carries ₱100 (not ₱200 each = ₱400/month).
        if ($type === 'pagibig') {
            $monthlyShare = min($monthlyShare, PayrollRates::get('pagibig_monthly_cap'));
        }

        // Deduct 50% on the 15th cutoff; the end-of-month cutoff takes the
        // remainder so the two halves always sum to the exact monthly share.
        $firstHalf = round($monthlyShare / 2, 2);

        return $cutoffType === 'first_half'
            ? $firstHalf
            : round($monthlyShare - $firstHalf, 2);
    }

    /**
     * Sum of late and undertime minutes in the period × per-minute rate.
     * Uses the first time-in and the last time-out of each day.
     */
    private function lateUndertimeDeduction(Employee $employee, PayrollPeriod $period, float $hourly): float
    {
        $schedule = $employee->schedule;
        if (! $schedule || ! $schedule->time_in || ! $schedule->time_out) {
            return 0.0;
        }

        $logs = $employee->attendanceLogs()
            ->whereBetween('logged_at', [$period->period_start->copy()->startOfDay(), $period->period_end->copy()->endOfDay()])
            ->orderBy('logged_at')
            ->get()
            ->groupBy(fn ($log) => $log->logged_at->toDateString());

        // Dates where an approved early-leave / half-day excuses leaving early.
        $excusedOut = $employee->leaveRequests()
            ->where('status', 'approved')
            ->where(fn ($q) => $q->where('is_early_leave', true)->orWhereIn('day_portion', ['half_am', 'half_pm']))
            ->whereBetween('date_from', [$period->period_start, $period->period_end])
            ->pluck('date_from')->map(fn ($d) => $d->toDateString())->flip();

        $minutes = 0;
        foreach ($logs as $date => $dayLogs) {
            $in = $dayLogs->firstWhere('log_type', 'time_in')?->logged_at;
            $out = $dayLogs->where('log_type', 'time_out')->last()?->logged_at;

            if ($in) {
                $expectedIn = $in->copy()->setTimeFromTimeString($schedule->time_in)->addMinutes((int) $schedule->grace_minutes);
                if ($in->gt($expectedIn)) {
                    $minutes += (int) $expectedIn->diffInMinutes($in);
                }
            }
            if ($out && ! isset($excusedOut[$date])) {
                $expectedOut = $out->copy()->setTimeFromTimeString($schedule->time_out);
                if ($out->lt($expectedOut)) {
                    $minutes += (int) $out->diffInMinutes($expectedOut);
                }
            }
        }

        return round($minutes * ($hourly / 60), 2);
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
