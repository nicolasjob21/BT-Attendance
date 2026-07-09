<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
            'approved_at' => 'datetime',
        ];
    }

    public function multiplier(): float
    {
        return self::MULTIPLIERS[$this->ot_type] ?? 1.25;
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
