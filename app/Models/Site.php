<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An authorized attendance location with a circular geofence: the main
 * office, a project site, or a temporary venue.
 */
class Site extends Model
{
    public const TYPES = [
        'office' => 'Main office',
        'project_site' => 'Project site',
        'temporary' => 'Temporary / alternate site',
    ];

    public const STATUSES = [
        'active' => 'Active',
        'inactive' => 'Inactive',
        'completed' => 'Completed',
    ];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'is_headquarters' => 'boolean',
            'active_from' => 'date',
            'active_until' => 'date',
        ];
    }

    public function attendanceLogs(): HasMany
    {
        return $this->hasMany(AttendanceLog::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(EmployeeProjectAssignment::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Sites that may accept a punch on the given day: status active and, when
     * an activation window is set, the day falls inside it.
     */
    public function scopeActiveOn(Builder $query, ?Carbon $on = null): Builder
    {
        $day = ($on ?? Carbon::today())->toDateString();

        return $query->where('status', 'active')
            ->where(fn ($q) => $q->whereNull('active_from')->orWhereDate('active_from', '<=', $day))
            ->where(fn ($q) => $q->whereNull('active_until')->orWhereDate('active_until', '>=', $day));
    }

    public function isActiveOn(?Carbon $on = null): bool
    {
        $day = ($on ?? Carbon::today())->startOfDay();

        return $this->status === 'active'
            && ($this->active_from === null || $this->active_from->lte($day))
            && ($this->active_until === null || $this->active_until->gte($day));
    }

    public function isOffice(): bool
    {
        return $this->type === 'office';
    }

    public function getTypeLabelAttribute(): string
    {
        return self::TYPES[$this->type] ?? ucfirst(str_replace('_', ' ', (string) $this->type));
    }
}
