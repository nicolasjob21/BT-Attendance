<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Enforce geofence on clock in/out
    |--------------------------------------------------------------------------
    |
    | Every clock event is matched against the nearest registered work site and
    | stamped with the distance and whether it fell inside that site's geofence.
    |
    | When true, a punch that lands outside every site's geofence is REJECTED —
    | the employee must be physically on-site to clock in or out. When false,
    | the punch is still recorded but flagged as out-of-fence for HR review.
    |
    | Leave this false until all work sites (HQ + client sites) are registered,
    | otherwise staff at an unregistered location will be unable to clock in.
    |
    */
    'enforce_geofence' => (bool) env('ATTENDANCE_ENFORCE_GEOFENCE', false),

];
