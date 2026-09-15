<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Check Point module defaults
    |--------------------------------------------------------------------------
    |
    | Fallbacks used when HR has not saved values on the Check Point settings
    | page (stored in the `settings` table under "checkpoints.*").
    |
    */

    'defaults' => [
        'checkpoints_per_day' => 3,
        'minimum_interval_minutes' => 45,
        'maximum_interval_minutes' => 180,
        'response_window_minutes' => 10,
    ],

    // Library HR picks from when configuring a campaign. Free text can be added.
    'photo_instructions' => [
        'Capture the project entrance.',
        'Capture the project signboard.',
        'Capture the site office.',
        'Capture the current work area.',
        'Capture the equipment or materials area.',
        'Capture the designated site landmark.',
    ],

    // How often the opportunistic sweep (open due / expire lapsed checkpoints)
    // may run from a web request when the scheduler is not running.
    'sweep_throttle_seconds' => 30,

];
