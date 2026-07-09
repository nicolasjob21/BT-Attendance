<?php

namespace App\Support;

/**
 * Geospatial helpers. Kept framework-free so it is trivial to unit-test.
 */
class Geo
{
    /** Earth mean radius in metres. */
    private const EARTH_RADIUS_M = 6371000.0;

    /**
     * Great-circle (haversine) distance between two lat/lng points, in metres.
     */
    public static function distanceMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * self::EARTH_RADIUS_M * asin(min(1.0, sqrt($a)));
    }
}
