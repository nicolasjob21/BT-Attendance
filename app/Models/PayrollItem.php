<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
        ];
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
}
