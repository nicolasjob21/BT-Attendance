<?php

namespace App\Services;

use App\Models\Site;

/** Outcome of GeofenceService::evaluate() for one punch. */
final class GeofenceResult
{
    public function __construct(
        public readonly string $status,
        public readonly string $message,
        /** Location the punch is credited to (only when inside a fence). */
        public readonly ?Site $site = null,
        /** Closest active location, inside or not. */
        public readonly ?Site $nearest = null,
        public readonly ?float $distance = null,
        public readonly bool $within = false,
        /** Project site the employee is assigned to, if any. */
        public readonly ?Site $assignedSite = null,
        public readonly ?float $accuracy = null,
    ) {}

    /** Anything HR should look at: outside every fence, no GPS, or a weak fix. */
    public function isException(): bool
    {
        return in_array($this->status, [
            GeofenceService::OUTSIDE_AUTHORIZED_AREA,
            GeofenceService::GPS_UNAVAILABLE,
            GeofenceService::LOW_ACCURACY,
        ], true);
    }

    /** Column values to stamp on the attendance log. */
    public function toLogAttributes(): array
    {
        return [
            'site_id' => $this->site?->id,
            'assigned_site_id' => $this->assignedSite?->id,
            'distance_m' => $this->distance !== null ? round($this->distance, 2) : null,
            'gps_accuracy_m' => $this->accuracy !== null ? round($this->accuracy, 2) : null,
            'within_geofence' => $this->within,
            'location_status' => $this->status,
            'location_validation_message' => $this->message,
        ];
    }
}
