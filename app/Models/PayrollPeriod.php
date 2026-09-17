<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
            'generated_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function isClosed(): bool
    {
        return $this->status === 'closed';
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    /**
     * The semi-monthly cutoff that contains a date: 1–15 or 16–end of month.
     *
     * @return array{start: Carbon, end: Carbon, cutoff_type: string}
     */
    public static function cutoffFor(Carbon $date): array
    {
        $d = $date->copy()->startOfDay();
        if ($d->day <= 15) {
            return ['start' => $d->copy()->startOfMonth(), 'end' => $d->copy()->day(15), 'cutoff_type' => 'first_half'];
        }

        return ['start' => $d->copy()->day(16), 'end' => $d->copy()->endOfMonth()->startOfDay(), 'cutoff_type' => 'second_half'];
    }

    /**
     * Find or create the period for the cutoff containing $date. Pay date is
     * the cutoff end plus the configured offset (default 5 days).
     */
    public static function ensureFor(Carbon $date, ?int $payDateOffsetDays = null): self
    {
        $c = self::cutoffFor($date);
        $offset = $payDateOffsetDays ?? (int) Setting::get('payroll.pay_date_offset_days', 5);

        $existing = self::whereDate('period_start', $c['start']->toDateString())
            ->whereDate('period_end', $c['end']->toDateString())
            ->first();

        return $existing ?? self::create([
            'period_start' => $c['start']->toDateString(),
            'period_end' => $c['end']->toDateString(),
            'cutoff_type' => $c['cutoff_type'],
            'pay_date' => $c['end']->copy()->addDays($offset)->toDateString(),
            'status' => 'open',
        ]);
    }

    /** The period right after this one. */
    public function next(): self
    {
        return self::ensureFor($this->period_end->copy()->addDay());
    }

    public function label(): string
    {
        return $this->period_start->format('M j').' – '.$this->period_end->format('M j, Y');
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

        return $from->format('M j').' – '.$to->format('M j');
    }

    public function payrollItems(): HasMany
    {
        return $this->hasMany(PayrollItem::class);
    }
}
