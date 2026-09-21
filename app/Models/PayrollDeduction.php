<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A balance an employee pays back through payroll, one installment per
 * cutoff, until it reaches zero: a company loan / cash advance, or their
 * share of an item that went missing at a site.
 */
class PayrollDeduction extends Model
{
    public const LOAN = 'loan';

    public const MISSING_ITEM = 'missing_item';

    public const TYPES = [self::LOAN => 'Loan', self::MISSING_ITEM => 'Missing item'];

    public const ACTIVE = 'active';

    public const PAID = 'paid';

    public const CANCELLED = 'cancelled';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'incident_date' => 'date',
            'starts_on' => 'date',
            'total_amount' => 'decimal:2',
            'installment_amount' => 'decimal:2',
            'balance' => 'decimal:2',
            'cancelled_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(PayrollDeductionPayment::class);
    }

    public function isLoan(): bool
    {
        return $this->type === self::LOAN;
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }

    /** Amount recovered so far. */
    public function paid(): float
    {
        return round((float) $this->total_amount - (float) $this->balance, 2);
    }

    /** What the next cutoff will take: the installment, or whatever is left. */
    public function nextInstallment(): float
    {
        return round(min((float) $this->installment_amount, (float) $this->balance), 2);
    }

    /** Cutoffs still needed to clear the balance at the current installment. */
    public function cutoffsLeft(): int
    {
        return (float) $this->installment_amount > 0 ? (int) ceil((float) $this->balance / (float) $this->installment_amount) : 0;
    }

    /** Deductions that still take money and are due by the end of a cutoff. */
    public function scopeDueFor(Builder $q, PayrollPeriod $period): Builder
    {
        return $q->where('status', self::ACTIVE)
            ->where('balance', '>', 0)
            ->whereDate('starts_on', '<=', $period->period_end->toDateString());
    }

    /** Put money back on the balance (a payroll line was recomputed or removed). */
    public function refund(float $amount): void
    {
        $this->balance = round((float) $this->balance + $amount, 2);
        if ($this->status === self::PAID && $this->balance > 0) {
            $this->status = self::ACTIVE;
        }
        $this->save();
    }

    /** Take an installment off the balance; mark paid when it reaches zero. */
    public function collect(float $amount): void
    {
        $this->balance = round(max(0, (float) $this->balance - $amount), 2);
        if ($this->balance <= 0) {
            $this->status = self::PAID;
        }
        $this->save();
    }
}
