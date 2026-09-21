<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One installment taken on one payroll line. */
class PayrollDeductionPayment extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    public function deduction(): BelongsTo
    {
        return $this->belongsTo(PayrollDeduction::class, 'payroll_deduction_id');
    }

    public function payrollItem(): BelongsTo
    {
        return $this->belongsTo(PayrollItem::class);
    }

    public function payrollPeriod(): BelongsTo
    {
        return $this->belongsTo(PayrollPeriod::class);
    }

    /** What was still owed right after this installment (shown on the payslip). */
    public function balanceAfter(): float
    {
        $takenSoFar = (float) static::where('payroll_deduction_id', $this->payroll_deduction_id)
            ->where(fn ($q) => $q->where('payroll_period_id', '<=', $this->payroll_period_id))
            ->sum('amount');

        return round(max(0, (float) $this->deduction->total_amount - $takenSoFar), 2);
    }
}
