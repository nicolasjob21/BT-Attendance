<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveRequest extends Model
{
    protected $guarded = [];

    /** Human labels for the day portion. */
    public const PORTION_LABELS = [
        'full' => 'Full day',
        'half_am' => 'Half day — Morning',
        'half_pm' => 'Half day — Afternoon',
    ];

    /** True when the request covers only half a day. */
    public function isHalfDay(): bool
    {
        return $this->day_portion !== 'full';
    }

    protected function casts(): array
    {
        return [
            'date_from' => 'date',
            'date_to' => 'date',
            'days' => 'decimal:2',
            'is_early_leave' => 'boolean',
            'approved_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'approved_by');
    }
}
