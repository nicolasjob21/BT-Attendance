<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\User;
use App\Services\GeofenceService;

class AttendanceLog extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'logged_at' => 'datetime',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'distance_m' => 'decimal:2',
            'gps_accuracy_m' => 'decimal:2',
            'within_geofence' => 'boolean',
            'synced_offline' => 'boolean',
            'ot_verified_at' => 'datetime',
            'location_verified_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** Location the punch was matched to (frozen at punch time). */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** Project site the employee was assigned to when they punched. */
    public function assignedSite(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'assigned_site_id');
    }

    /** HR user who reviewed an out-of-area / low-accuracy punch. */
    public function locationVerifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'location_verified_by');
    }

    /** True when the punch's location evidence is flagged for HR attention. */
    public function hasLocationException(): bool
    {
        return in_array($this->location_status, [
            GeofenceService::OUTSIDE_AUTHORIZED_AREA,
            GeofenceService::GPS_UNAVAILABLE,
            GeofenceService::LOW_ACCURACY,
        ], true);
    }

    /** HR user who verified an unusually long (13h+) day. */
    public function otVerifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ot_verified_by');
    }
}
