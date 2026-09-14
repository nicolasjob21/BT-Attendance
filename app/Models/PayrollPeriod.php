<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class PayrollPeriod extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'pay_date' => 'date',
        ];
    }

    public function label(): string
    {
        return $this->period_start->format('M j') . ' – ' . $this->period_end->format('M j, Y');
    }

    /**
     * Overtime is paid one cutoff in arrears: OT worked on the 1st–15th is
     * paid in the 16th–end payroll, and OT worked on the 16th–end is paid in
     * the following month's 1st–15th payroll. This returns the OT window
     * (start, end) for this period.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public function overtimeWindow(): array
    {
        $isFirstHalf = $this->cutoff_type === 'first_half'
            || ($this->cutoff_type === null && $this->period_start->day <= 15);

        if ($isFirstHalf) {
            $prev = $this->period_start->copy()->subMonthNoOverflow();

            return [$prev->copy()->day(16)->startOfDay(), $prev->copy()->endOfMonth()->startOfDay()];
        }

        return [$this->period_start->copy()->startOfMonth(), $this->period_start->copy()->day(15)->startOfDay()];
    }

    /** "Aug 16 – Aug 31" — the OT window this payroll pays for. */
    public function overtimeWindowLabel(): string
    {
        [$from, $to] = $this->overtimeWindow();

        return $from->format('M j') . ' – ' . $to->format('M j');
    }

    public function payrollItems(): HasMany
    {
        return $this->hasMany(PayrollItem::class);
    }
}
