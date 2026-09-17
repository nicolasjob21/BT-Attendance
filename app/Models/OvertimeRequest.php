<?php

namespace App\Models;

use App\Services\Payroll\PayrollRates;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A pre-approved overtime request. Filed before the OT is worked with a
 * planned window and a reason; once the employee clocks out that day the
 * actual hours are derived from attendance and stored in `hours`.
 */
class OvertimeRequest extends Model
{
    protected $guarded = [];

    /** PH overtime premium multipliers by type. */
    public const MULTIPLIERS = [
        'regular' => 1.25,   // regular overtime
        'rest_day' => 1.30,  // rest-day work
        'holiday' => 2.00,   // regular holiday
    ];

    /** Human labels (with premium) by type. */
    public const TYPE_LABELS = [
        'regular' => 'Regular OT · 125%',
        'rest_day' => 'Rest-day OT · 130%',
        'holiday' => 'Holiday OT · 200%',
    ];

    protected function casts(): array
    {
        return [
            'ot_date' => 'date',
            'hours' => 'decimal:2',
            'requested_hours' => 'decimal:2',
            'approved_at' => 'datetime',
            'hours_synced_at' => 'datetime',
        ];
    }

    public function multiplier(): float
    {
        return PayrollRates::otMultiplier($this->ot_type ?? 'regular');
    }

    /**
     * Hours that payroll pays: the actual worked overtime, capped at what was
     * approved in advance. Null while the employee hasn't clocked out yet.
     */
    public function payableHours(): ?float
    {
        if ($this->hours === null) {
            return null;
        }
        $actual = (float) $this->hours;

        return $this->requested_hours !== null ? min($actual, (float) $this->requested_hours) : $actual;
    }

    /** "5:30 PM – 8:30 PM" for the planned window, or null for legacy rows. */
    public function plannedWindow(): ?string
    {
        if (! $this->planned_start || ! $this->planned_end) {
            return null;
        }

        return Carbon::parse($this->planned_start)->format('g:i A').' – '.Carbon::parse($this->planned_end)->format('g:i A');
    }

    /**
     * Cutoff this OT is paid in (one cutoff in arrears): OT on the 1st–15th
     * → the 16th–end payroll of the same month; OT on the 16th–end → next
     * month's 1st–15th payroll. Returns "Sep 16 – Sep 30" style labels.
     */
    public function payoutCutoffLabel(): string
    {
        $d = $this->ot_date;
        if ($d->day <= 15) {
            return $d->copy()->day(16)->format('M j').' – '.$d->copy()->endOfMonth()->format('M j').' payroll';
        }
        $next = $d->copy()->addMonthNoOverflow();

        return $next->copy()->day(1)->format('M j').' – '.$next->copy()->day(15)->format('M j').' payroll';
    }

    /** True once the OT date has passed (or is today) and actual hours can be derived. */
    public function awaitingActualHours(): bool
    {
        return $this->status === 'approved' && $this->hours === null && $this->ot_date->lte(Carbon::today());
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'approved_by');
    }
}
