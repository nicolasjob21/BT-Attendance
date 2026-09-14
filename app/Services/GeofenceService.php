<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\Site;
use App\Support\Geo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Server-side location validation for a clock event.
 *
 * The employee's GPS fix is compared against EVERY active attendance location
 * (main office, active project sites, temporary sites) — not just the project
 * they are assigned to. The client-side check on the map is only a preview;
 * this is the source of truth.
 */
class GeofenceService
{
    public const VERIFIED_LOCATION = 'verified_location';
    public const AUTHORIZED_ALTERNATE_LOCATION = 'authorized_alternate_location';
    public const OUTSIDE_AUTHORIZED_AREA = 'outside_authorized_area';
    public const GPS_UNAVAILABLE = 'gps_unavailable';
    public const LOW_ACCURACY = 'low_accuracy';

    public const MODES = ['warning', 'approval', 'strict'];

    public const LABELS = [
        self::VERIFIED_LOCATION => 'Verified location',
        self::AUTHORIZED_ALTERNATE_LOCATION => 'Authorized alternate location',
        self::OUTSIDE_AUTHORIZED_AREA => 'Outside authorized area',
        self::GPS_UNAVAILABLE => 'GPS unavailable',
        self::LOW_ACCURACY => 'Low GPS accuracy',
    ];

    public function mode(): string
    {
        $mode = (string) config('attendance.geofence_mode', 'warning');

        return in_array($mode, self::MODES, true) ? $mode : 'warning';
    }

    public function minAccuracyMeters(): int
    {
        return max(1, (int) config('attendance.min_gps_accuracy_m', 100));
    }

    /**
     * Great-circle distance between two lat/long points, in meters (Haversine).
     */
    public function distanceMeters(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        return Geo::distanceMeters($lat1, $lon1, $lat2, $lon2);
    }

    /**
     * Validate a captured coordinate against one site's geofence.
     *
     * @return array{distance: float, within: bool}
     */
    public function check(Site $site, float $lat, float $lon): array
    {
        $distance = $this->distanceMeters((float) $site->latitude, (float) $site->longitude, $lat, $lon);

        return [
            'distance' => round($distance, 2),
            'within' => $distance <= (float) $site->geofence_radius_m,
        ];
    }

    /**
     * Evaluate a punch for an employee.
     *
     * @param  Collection<int, Site>|null  $sites  Active sites to test against; loaded when null.
     */
    public function evaluate(
        Employee $employee,
        ?float $lat,
        ?float $lng,
        ?float $accuracy = null,
        ?Collection $sites = null,
        ?Carbon $at = null,
    ): GeofenceResult {
        $at ??= Carbon::now();
        $sites ??= Site::query()->activeOn($at)->get();
        $assigned = $employee->activeAssignment()->activeOn($at)->with('site')->first()?->site;

        if ($lat === null || $lng === null) {
            return new GeofenceResult(
                status: self::GPS_UNAVAILABLE,
                message: 'Location was not available on the device, so this punch could not be verified against any work site.',
                assignedSite: $assigned,
                accuracy: $accuracy,
            );
        }

        // Nearest active location, and whether the fix is inside its circle.
        $nearest = null;
        $nearestDistance = null;
        foreach ($sites as $site) {
            $d = $this->distanceMeters($lat, $lng, (float) $site->latitude, (float) $site->longitude);
            if ($nearestDistance === null || $d < $nearestDistance) {
                $nearest = $site;
                $nearestDistance = $d;
            }
        }

        $within = $nearest !== null && $nearestDistance <= (float) $nearest->geofence_radius_m;

        // A fix that could be anywhere within a very wide circle is not
        // reliable evidence, whichever side of the fence it falls on.
        if ($accuracy !== null && $accuracy > $this->minAccuracyMeters()) {
            return new GeofenceResult(
                status: self::LOW_ACCURACY,
                message: 'GPS accuracy was ±' . number_format($accuracy) . ' m, too poor to reliably confirm the work site'
                    . ($nearest ? ' (nearest: ' . $nearest->name . ', ' . number_format($nearestDistance) . ' m away).' : '.'),
                site: $within ? $nearest : null,
                nearest: $nearest,
                distance: $nearestDistance,
                within: $within,
                assignedSite: $assigned,
                accuracy: $accuracy,
            );
        }

        if (! $within) {
            $howFar = $nearest
                ? 'about ' . number_format($nearestDistance) . ' m from ' . $nearest->name
                : 'outside every registered work site';

            return new GeofenceResult(
                status: self::OUTSIDE_AUTHORIZED_AREA,
                message: "Outside the authorized attendance area — {$howFar}.",
                nearest: $nearest,
                distance: $nearestDistance,
                within: false,
                assignedSite: $assigned,
                accuracy: $accuracy,
            );
        }

        // Inside a fence. Attendance at the assigned project (or at any site
        // when the employee has no assignment) is fully verified; attendance at
        // a different authorized site — typically the main office — is allowed
        // but recorded as an alternate location so HR can tell them apart.
        $alternate = $assigned !== null && $assigned->id !== $nearest->id;

        return new GeofenceResult(
            status: $alternate ? self::AUTHORIZED_ALTERNATE_LOCATION : self::VERIFIED_LOCATION,
            message: $alternate
                ? "At {$nearest->name}, which is an authorized location, but not the assigned project ({$assigned->name})."
                : "Inside the {$nearest->name} geofence.",
            site: $nearest,
            nearest: $nearest,
            distance: $nearestDistance,
            within: true,
            assignedSite: $assigned,
            accuracy: $accuracy,
        );
    }

    /**
     * Whether the current mode refuses to record a punch with this result.
     * Only strict mode blocks; warning/approval always keep the record.
     */
    public function blocks(GeofenceResult $result): bool
    {
        return $this->mode() === 'strict' && $result->isException();
    }

    /**
     * Initial HR review state for a punch: approval mode parks every exception
     * as pending; warning mode records it as an exception with no gate.
     */
    public function initialVerificationStatus(GeofenceResult $result): ?string
    {
        if (! $result->isException()) {
            return null;
        }

        return $this->mode() === 'approval' ? 'pending' : null;
    }

    public static function label(?string $status): string
    {
        return self::LABELS[$status] ?? 'Not checked';
    }
}
