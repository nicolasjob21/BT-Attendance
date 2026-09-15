<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One random presence check for one employee. The scheduled time is secret
 * until the checkpoint opens; the evidence captured on submission is frozen.
 */
class Checkpoint extends Model
{
    public const SCHEDULED = 'scheduled';

    public const OPEN = 'open';

    public const SUBMITTED = 'submitted';

    public const VERIFIED = 'verified';

    public const FAILED = 'failed';

    public const MISSED = 'missed';

    public const EXPIRED = 'expired';

    public const PENDING_REVIEW = 'pending_review';

    public const CANCELLED = 'cancelled';

    public const STATUSES = [
        self::SCHEDULED => 'Scheduled',
        self::OPEN => 'Open',
        self::SUBMITTED => 'Submitted',
        self::VERIFIED => 'Verified',
        self::FAILED => 'Failed',
        self::MISSED => 'Missed',
        self::EXPIRED => 'Expired',
        self::PENDING_REVIEW => 'Pending review',
        self::CANCELLED => 'Cancelled',
    ];

    /** Statuses that raise an attendance exception for HR review. */
    public const EXCEPTION_STATUSES = [self::FAILED, self::MISSED, self::EXPIRED, self::PENDING_REVIEW];

    /** Backend validation outcomes (stored in failure_reason; null = verified). */
    public const RESULTS = [
        'verified_presence' => 'Verified presence',
        'outside_geofence' => 'Outside geofence',
        'gps_unavailable' => 'GPS unavailable',
        'low_gps_accuracy' => 'Low GPS accuracy',
        'photo_missing' => 'Photo missing',
        'checkpoint_expired' => 'Checkpoint expired',
        'duplicate_submission' => 'Duplicate submission',
        'unauthorized_employee' => 'Unauthorized employee',
        'pending_review' => 'Pending review',
        'no_response' => 'No response',
        'campaign_paused' => 'Campaign paused',
        'campaign_ended' => 'Campaign ended',
    ];

    public const REVIEW_RESULTS = [
        'valid_reason' => 'Valid reason',
        'approved_official_errand' => 'Approved official errand',
        'gps_issue' => 'GPS issue',
        'device_or_network_issue' => 'Device or network issue',
        'unauthorized_site_exit' => 'Unauthorized site exit',
        'insufficient_evidence' => 'Insufficient evidence',
        'confirmed_attendance' => 'Confirmed attendance',
        'requires_further_investigation' => 'Requires further investigation',
    ];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'scheduled_for' => 'date',
            'scheduled_at' => 'datetime',
            'opened_at' => 'datetime',
            'expires_at' => 'datetime',
            'submitted_at' => 'datetime',
            'server_timestamp' => 'datetime',
            'client_timestamp' => 'datetime',
            'reviewed_at' => 'datetime',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'gps_accuracy_meters' => 'decimal:2',
            'distance_from_site_meters' => 'decimal:2',
            'within_geofence' => 'boolean',
        ];
    }

    // ── Relationships ───────────────────────────────────────────────

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(CheckpointCampaign::class, 'campaign_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'project_site_id');
    }

    /** Authorized location the fix actually landed in (may differ from the campaign site). */
    public function matchedSite(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'matched_site_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(CheckpointAuditLog::class)->latest('created_at')->latest('id');
    }

    // ── Scopes ──────────────────────────────────────────────────────

    public function scopeStatus(Builder $q, string|array $status): Builder
    {
        return $q->whereIn('verification_status', (array) $status);
    }

    /** Checkpoints HR still has to look at. */
    public function scopePendingReview(Builder $q): Builder
    {
        return $q->where('review_status', 'pending');
    }

    public function scopeExceptions(Builder $q): Builder
    {
        return $q->whereIn('verification_status', self::EXCEPTION_STATUSES);
    }

    // ── Helpers ─────────────────────────────────────────────────────

    public function reference(): string
    {
        return 'CP-'.str_pad((string) $this->id, 6, '0', STR_PAD_LEFT);
    }

    public function isOpen(): bool
    {
        return $this->verification_status === self::OPEN;
    }

    public function isExpired(?Carbon $now = null): bool
    {
        return $this->expires_at !== null && ($now ?? Carbon::now())->gt($this->expires_at);
    }

    public function isException(): bool
    {
        return in_array($this->verification_status, self::EXCEPTION_STATUSES, true);
    }

    public function needsReview(): bool
    {
        return $this->review_status === 'pending';
    }

    public function hasSubmission(): bool
    {
        return $this->submitted_at !== null;
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUSES[$this->verification_status] ?? ucfirst(str_replace('_', ' ', $this->verification_status));
    }

    public function getResultLabelAttribute(): ?string
    {
        if ($this->verification_status === self::VERIFIED) {
            return self::RESULTS['verified_presence'];
        }

        return $this->failure_reason ? (self::RESULTS[$this->failure_reason] ?? ucfirst(str_replace('_', ' ', $this->failure_reason))) : null;
    }

    public function getReviewResultLabelAttribute(): ?string
    {
        return $this->review_result ? (self::REVIEW_RESULTS[$this->review_result] ?? ucfirst(str_replace('_', ' ', $this->review_result))) : null;
    }
}
