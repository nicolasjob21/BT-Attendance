<?php

// Backwards compatibility: the old boolean toggle maps onto the strict mode.
$legacyEnforce = filter_var(env('ATTENDANCE_ENFORCE_GEOFENCE', false), FILTER_VALIDATE_BOOLEAN);

return [

    /*
    |--------------------------------------------------------------------------
    | Geofence mode
    |--------------------------------------------------------------------------
    |
    | Every clock event is matched against ALL active attendance locations
    | (main office, active project sites, temporary sites) and stamped with the
    | nearest location, the distance, GPS accuracy, and a location status.
    |
    | What happens when a punch lands OUTSIDE every active geofence:
    |
    |   warning   Record the punch, flag it as an exception for HR. (default)
    |             GPS is unreliable near buildings and on construction sites,
    |             so this avoids losing real attendance.
    |   approval  Record the punch as "pending" — it must be approved by HR
    |             before it is trusted.
    |   strict    Reject the punch. The employee must be inside an active
    |             geofence to clock in or out.
    |
    */
    'geofence_mode' => env('ATTENDANCE_GEOFENCE_MODE', $legacyEnforce ? 'strict' : 'warning'),

    /*
    |--------------------------------------------------------------------------
    | Minimum usable GPS accuracy (metres)
    |--------------------------------------------------------------------------
    |
    | A fix whose reported accuracy radius is worse than this is treated as
    | "low accuracy": the punch is still recorded (except in strict mode) but
    | flagged so HR knows the location evidence is weak.
    |
    */
    'min_gps_accuracy_m' => (int) env('ATTENDANCE_MIN_GPS_ACCURACY_M', 100),

];
