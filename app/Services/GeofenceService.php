<?php

namespace App\Services;

use App\Models\Site;

class GeofenceService
{
    /**
     * Great-circle distance between two lat/long points, in meters (Haversine).
     */
    public function distanceMeters(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6_371_000; // meters

        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }

    /**
     * Validate a captured coordinate against a site's geofence.
     *
     * @return array{distance: float, within: bool}
     */
    public function check(Site $site, float $lat, float $lon): array
    {
        $distance = $this->distanceMeters(
            (float) $site->latitude,
            (float) $site->longitude,
            $lat,
            $lon,
        );

        return [
            'distance' => round($distance, 2),
            'within' => $distance <= $site->geofence_radius_m,
        ];
    }
}
