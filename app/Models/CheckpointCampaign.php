<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * ONE shared live presence checkpoint for a project site. When HR activates
 * it the server stamps a single start time and a single deadline that every
 * selected employee must meet.
 */
class CheckpointCampaign extends Model
{
    public const DRAFT = 'draft';

    public const ACTIVE = 'active';

    public const PAUSED = 'paused';

    public const EXPIRED = 'expired';

    public const COMPLETED = 'completed';

    public const CANCELLED = 'cancelled';

    public const STATUSES = [
        self::DRAFT => 'Draft',
        self::ACTIVE => 'Active',
        self::PAUSED => 'Paused',
        self::EXPIRED => 'Expired',
        self::COMPLETED => 'Completed',
        self::CANCELLED => 'Cancelled',
    ];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'scheduled_start_at' => 'datetime',
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
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

    /** One response row per employee (created on activation). */
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

    /** Campaigns whose window is running or frozen. */
    public function scopeLive(Builder $q): Builder
    {
        return $q->whereIn('status', [self::ACTIVE, self::PAUSED]);
    }

    /** Finished campaigns, kept as history. */
    public function scopeHistory(Builder $q): Builder
    {
        return $q->whereIn('status', [self::COMPLETED, self::CANCELLED]);
    }

    // ── State helpers ───────────────────────────────────────────────

    public function isDraft(): bool
    {
        return $this->status === self::DRAFT;
    }

    /** Draft with a planned start the dispatcher will honour. */
    public function isScheduled(): bool
    {
        return $this->status === self::DRAFT && $this->scheduled_start_at !== null;
    }

    /** True when the start time was drawn by the system rather than typed by the admin. */
    public function isRandomlyScheduled(): bool
    {
        return $this->schedule_mode === 'random' && $this->scheduled_start_at !== null;
    }

    /** "8:30 AM – 5:30 PM" for the window the random time was drawn from. */
    public function randomWindowLabel(): ?string
    {
        if (! $this->random_window_start || ! $this->random_window_end) {
            return null;
        }

        return Carbon::parse($this->random_window_start)->format('g:i A').' – '.Carbon::parse($this->random_window_end)->format('g:i A');
    }

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

    /** Accepting submissions right now (active and inside the window). */
    public function acceptsSubmissions(?Carbon $now = null): bool
    {
        $now ??= Carbon::now();

        return $this->isActive() && $this->starts_at && $this->expires_at
            && $now->gte($this->starts_at) && $now->lte($this->expires_at);
    }

    public function secondsRemaining(?Carbon $now = null): int
    {
        if (! $this->expires_at) {
            return 0;
        }

        return max(0, (int) ($now ?? Carbon::now())->diffInSeconds($this->expires_at, false));
    }

    public function getStatusLabelAttribute(): string
    {
        if ($this->isScheduled()) {
            return 'Scheduled';
        }

        return self::STATUSES[$this->status] ?? ucfirst($this->status);
    }
}
