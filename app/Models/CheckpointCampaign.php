<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A temporary random-presence-verification session for one project site.
 * Created as a draft, reviewed, then activated now or scheduled for later.
 */
class CheckpointCampaign extends Model
{
    public const DRAFT = 'draft';

    public const SCHEDULED = 'scheduled';

    public const ACTIVE = 'active';

    public const PAUSED = 'paused';

    public const COMPLETED = 'completed';

    public const CANCELLED = 'cancelled';

    public const STATUSES = [
        self::DRAFT => 'Draft',
        self::SCHEDULED => 'Scheduled',
        self::ACTIVE => 'Active',
        self::PAUSED => 'Paused',
        self::COMPLETED => 'Completed',
        self::CANCELLED => 'Cancelled',
    ];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'include_weekends' => 'boolean',
            'photo_instructions' => 'array',
            'activated_at' => 'datetime',
            'paused_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    // ── Relationships ───────────────────────────────────────────────

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'project_site_id');
    }

    public function participants(): HasMany
    {
        return $this->hasMany(CheckpointCampaignParticipant::class, 'campaign_id');
    }

    public function employees(): BelongsToMany
    {
        return $this->belongsToMany(Employee::class, 'checkpoint_campaign_participants', 'campaign_id', 'employee_id')
            ->withTimestamps();
    }

    public function checkpoints(): HasMany
    {
        return $this->hasMany(Checkpoint::class, 'campaign_id');
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(CheckpointAuditLog::class, 'campaign_id')->latest('created_at')->latest('id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function activator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'activated_by');
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    // ── Scopes ──────────────────────────────────────────────────────

    public function scopeStatus(Builder $q, string|array $status): Builder
    {
        return $q->whereIn('status', (array) $status);
    }

    /** Campaigns that are done (kept as history, never deleted). */
    public function scopeHistory(Builder $q): Builder
    {
        return $q->whereIn('status', [self::COMPLETED, self::CANCELLED]);
    }

    // ── State helpers ───────────────────────────────────────────────

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }

    public function isLive(): bool
    {
        return in_array($this->status, [self::ACTIVE, self::PAUSED], true);
    }

    public function isFinished(): bool
    {
        return in_array($this->status, [self::COMPLETED, self::CANCELLED], true);
    }

    public function canActivate(): bool
    {
        return in_array($this->status, [self::DRAFT, self::SCHEDULED], true);
    }

    public function canEdit(): bool
    {
        return in_array($this->status, [self::DRAFT, self::SCHEDULED], true);
    }

    /** Whether the campaign covers the given calendar day. */
    public function coversDay(Carbon $day): bool
    {
        if ($day->lt($this->start_date->copy()->startOfDay()) || $day->gt($this->end_date->copy()->endOfDay())) {
            return false;
        }

        return $this->include_weekends || ! $day->isWeekend();
    }

    /** Working window on a given day as [start, end]. */
    public function windowOn(Carbon $day): array
    {
        $date = $day->toDateString();

        return [
            Carbon::parse("{$date} {$this->working_start_time}"),
            Carbon::parse("{$date} {$this->working_end_time}"),
        ];
    }

    /** Whole-day minutes available for checkpoints. */
    public function windowMinutes(): int
    {
        [$s, $e] = $this->windowOn(Carbon::today());

        return max(0, (int) $s->diffInMinutes($e));
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUSES[$this->status] ?? ucfirst($this->status);
    }
}
