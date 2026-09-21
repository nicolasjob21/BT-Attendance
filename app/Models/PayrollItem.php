<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PayrollItem extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'basic_pay' => 'decimal:2',
            'overtime_pay' => 'decimal:2',
            'night_diff_pay' => 'decimal:2',
            'holiday_pay' => 'decimal:2',
            'gross_pay' => 'decimal:2',
            'late_undertime_deduction' => 'decimal:2',
            'absences_deduction' => 'decimal:2',
            'sss_deduction' => 'decimal:2',
            'philhealth_deduction' => 'decimal:2',
            'pagibig_deduction' => 'decimal:2',
            'withholding_tax' => 'decimal:2',
            'total_deductions' => 'decimal:2',
            'net_pay' => 'decimal:2',
            'allowances' => 'decimal:2',
            'other_deductions' => 'decimal:2',
            'loan_deduction' => 'decimal:2',
            'missing_item_deduction' => 'decimal:2',
            'half_day_deduction' => 'decimal:2',
            'adjusted_at' => 'datetime',
        ];
    }

    /** Money fields HR may edit on a payroll line. Gross / totals / net are always recomputed. */
    public const EDITABLE = [
        'basic_pay', 'overtime_pay', 'night_diff_pay', 'holiday_pay', 'allowances',
        'late_undertime_deduction', 'absences_deduction', 'half_day_deduction',
        'sss_deduction', 'philhealth_deduction', 'pagibig_deduction', 'withholding_tax', 'other_deductions',
    ];

    /** True once HR has edited this line by hand; auto-runs then leave it alone. */
    public function isAdjusted(): bool
    {
        return $this->adjusted_at !== null;
    }

    /** Recompute gross, total deductions and net from the component fields (in memory). */
    public function recomputeTotals(): static
    {
        $this->gross_pay = round((float) $this->basic_pay + (float) $this->overtime_pay + (float) $this->night_diff_pay + (float) $this->holiday_pay + (float) $this->allowances, 2);
        $this->total_deductions = round((float) $this->late_undertime_deduction + (float) $this->absences_deduction + (float) $this->half_day_deduction
            + (float) $this->sss_deduction + (float) $this->philhealth_deduction + (float) $this->pagibig_deduction + (float) $this->withholding_tax + (float) $this->other_deductions
            + (float) $this->loan_deduction + (float) $this->missing_item_deduction, 2);
        $this->net_pay = round($this->gross_pay - $this->total_deductions, 2);

        return $this;
    }

    public function adjuster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'adjusted_by');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function payrollPeriod(): BelongsTo
    {
        return $this->belongsTo(PayrollPeriod::class);
    }

    public function payslip(): HasOne
    {
        return $this->hasOne(Payslip::class);
    }

    /** Loan / missing-item installments taken on this line. */
    public function deductionPayments(): HasMany
    {
        return $this->hasMany(PayrollDeductionPayment::class)->with('deduction.site:id,name');
    }
}
