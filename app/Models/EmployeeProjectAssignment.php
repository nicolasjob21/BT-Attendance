<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Deployment of an employee to a project site for a period. Only one
 * assignment per employee is active at a time; ending one keeps it as history.
 */
class EmployeeProjectAssignment extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
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

    /** Assignments in force on a given day. */
    public function scopeActiveOn(Builder $query, ?Carbon $on = null): Builder
    {
        $day = ($on ?? Carbon::today())->toDateString();

        return $query->where('status', 'active')
            ->whereDate('start_date', '<=', $day)
            ->where(fn ($q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', $day));
    }

    /** Close the assignment as of the given day (defaults to today). */
    public function end(?Carbon $on = null, string $status = 'ended'): void
    {
        $this->update([
            'status' => $status,
            'end_date' => ($on ?? Carbon::today())->toDateString(),
        ]);
    }
}
