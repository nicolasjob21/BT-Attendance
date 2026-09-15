<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One employee's response to a shared checkpoint campaign. The campaign
 * owns the official start/deadline; this row owns the evidence and status.
 */
class Checkpoint extends Model
{
    // Response statuses
    public const PENDING = 'pending';

    public const NOTIFIED = 'notified';

    public const RESPONDED = 'responded';

    public const MISSED = 'missed';

    public const OUTSIDE_GEOFENCE = 'outside_geofence';

    public const GPS_UNAVAILABLE = 'gps_unavailable';

    public const CAMERA_PERMISSION_DENIED = 'camera_permission_denied';

    public const SUBMISSION_FAILED = 'submission_failed';

    public const PENDING_REVIEW = 'pending_review';

    public const APPROVED_EXCEPTION = 'approved_exception';

    public const REJECTED_EXCEPTION = 'rejected_exception';

    public const STATUSES = [
        self::PENDING => 'Pending',
        self::NOTIFIED => 'Notified – not responded',
        self::RESPONDED => 'Completed',
        self::MISSED => 'Missed',
        self::OUTSIDE_GEOFENCE => 'Outside geofence',
        self::GPS_UNAVAILABLE => 'GPS unavailable',
        self::CAMERA_PERMISSION_DENIED => 'Camera permission denied',
        self::SUBMISSION_FAILED => 'Submission failed',
        self::PENDING_REVIEW => 'Pending HR review',
        self::APPROVED_EXCEPTION => 'Approved exception',
        self::REJECTED_EXCEPTION => 'Rejected exception',
    ];

    /** Statuses that count as a valid, completed checkpoint. */
    public const COMPLETED_STATUSES = [self::RESPONDED, self::APPROVED_EXCEPTION];

    /** Statuses still waiting on the employee while the window is open. */
    public const WAITING_STATUSES = [self::PENDING, self::NOTIFIED, self::OUTSIDE_GEOFENCE, self::GPS_UNAVAILABLE, self::CAMERA_PERMISSION_DENIED, self::SUBMISSION_FAILED];

    /** Non-compliant statuses HR follows up on. */
    public const NON_COMPLIANT_STATUSES = [self::MISSED, self::OUTSIDE_GEOFENCE, self::GPS_UNAVAILABLE, self::CAMERA_PERMISSION_DENIED, self::SUBMISSION_FAILED, self::PENDING_REVIEW, self::REJECTED_EXCEPTION];

    // Verification results for completed responses
    public const COMPLETED = 'completed';

    public const COMPLETED_LOW_ACCURACY = 'completed_with_low_gps_accuracy';

    public const COMPLETED_AFTER_REVIEW = 'completed_after_review';

    public const VERIFICATION_RESULTS = [
        self::COMPLETED => 'Completed',
        self::COMPLETED_LOW_ACCURACY => 'Completed with low GPS accuracy',
        self::COMPLETED_AFTER_REVIEW => 'Completed after review',
    ];

    /** Backend validation outcome codes (failure_reason / last_attempt_result). */
    public const RESULTS = [
        'verified_presence' => 'Verified presence',
        'low_gps_accuracy' => 'Low GPS accuracy',
        'outside_geofence' => 'Outside geofence',
        'alternate_location' => 'At another authorized location',
        'gps_unavailable' => 'GPS unavailable',
        'photo_missing' => 'Photo missing',
        'checkpoint_expired' => 'Checkpoint expired',
        'checkpoint_paused' => 'Checkpoint paused',
        'duplicate_submission' => 'Already completed',
        'unauthorized_employee' => 'Unauthorized employee',
        'no_response' => 'No response',
    ];

    /** Problems an employee can report from the checkpoint page. */
    public const ISSUES = [
        'camera_denied' => 'Camera permission denied',
        'gps_unavailable' => 'GPS unavailable',
        'no_internet' => 'No internet connection',
        'device_problem' => 'Device problem',
    ];

    /** Reasons HR can record during follow-up. */
    public const HR_REASONS = [
        'no_internet' => 'No internet connection',
        'device_problem' => 'Device problem',
        'gps_problem' => 'GPS problem',
        'camera_permission' => 'Camera permission problem',
        'work_related' => 'Work-related reason',
        'temporarily_away' => 'Employee was temporarily away from the site',
        'forgot' => 'Employee forgot to complete the checkpoint',
        'ignored' => 'Employee ignored the checkpoint',
        'other' => 'Other reason',
    ];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'notified_at' => 'datetime',
            'seen_at' => 'datetime',
            'last_attempt_at' => 'datetime',
            'submitted_at' => 'datetime',
            'server_timestamp' => 'datetime',
            'client_timestamp' => 'datetime',
            'escalated_at' => 'datetime',
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

    public function matchedSite(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'matched_site_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(CheckpointReview::class)->latest('created_at')->latest('id');
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(CheckpointAuditLog::class)->latest('created_at')->latest('id');
    }

    // ── Scopes ──────────────────────────────────────────────────────

    public function scopeStatus(Builder $q, string|array $status): Builder
    {
        return $q->whereIn('status', (array) $status);
    }

    public function scopeCompleted(Builder $q): Builder
    {
        return $q->whereIn('status', self::COMPLETED_STATUSES);
    }

    public function scopeNonCompliant(Builder $q): Builder
    {
        return $q->whereIn('status', self::NON_COMPLIANT_STATUSES);
    }

    public function scopeNeedsReview(Builder $q): Builder
    {
        return $q->where('status', self::PENDING_REVIEW);
    }

    // ── Helpers ─────────────────────────────────────────────────────

    public function reference(): string
    {
        return 'CP-'.str_pad((string) $this->id, 6, '0', STR_PAD_LEFT);
    }

    public function isCompleted(): bool
    {
        return in_array($this->status, self::COMPLETED_STATUSES, true);
    }

    public function isWaiting(): bool
    {
        return in_array($this->status, self::WAITING_STATUSES, true);
    }

    public function isNonCompliant(): bool
    {
        return in_array($this->status, self::NON_COMPLIANT_STATUSES, true);
    }

    /** Whether HR can still act on this response (anything not a clean completion). */
    public function isReviewable(): bool
    {
        return $this->status !== self::RESPONDED;
    }

    public function hasSubmission(): bool
    {
        return $this->submitted_at !== null || $this->last_attempt_at !== null;
    }

    /** Seconds between the shared start and the accepted submission. */
    public function responseSeconds(): ?int
    {
        if (! $this->submitted_at || ! $this->campaign?->starts_at) {
            return null;
        }

        return (int) $this->campaign->starts_at->diffInSeconds($this->submitted_at);
    }

    public function getStatusLabelAttribute(): string
    {
        // Employees who reported "no internet" surface as such in the table.
        if ($this->issue_reported === 'no_internet' && in_array($this->status, [self::MISSED, self::PENDING, self::NOTIFIED], true)) {
            return 'No internet reported';
        }

        return self::STATUSES[$this->status] ?? ucfirst(str_replace('_', ' ', $this->status));
    }

    public function getVerificationLabelAttribute(): ?string
    {
        return $this->verification_result ? (self::VERIFICATION_RESULTS[$this->verification_result] ?? $this->verification_result) : null;
    }

    public function getResultLabelAttribute(): ?string
    {
        return $this->failure_reason ? (self::RESULTS[$this->failure_reason] ?? ucfirst(str_replace('_', ' ', $this->failure_reason))) : null;
    }

    public function getHrReasonLabelAttribute(): ?string
    {
        return $this->hr_reason ? (self::HR_REASONS[$this->hr_reason] ?? $this->hr_reason) : null;
    }

    public function getNotificationStatusAttribute(): string
    {
        if ($this->seen_at) {
            return 'Seen '.$this->seen_at->format('g:i A');
        }
        if ($this->notified_at) {
            return 'Sent '.$this->notified_at->format('g:i A');
        }

        return 'Not sent';
    }
}
