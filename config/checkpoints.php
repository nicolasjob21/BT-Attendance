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
        'response_window_minutes' => 10,
    ],

    // Instruction library HR picks from when creating a checkpoint.
    'instructions' => [
        'Capture the project entrance.',
        'Capture the project signboard.',
        'Capture the site office.',
        'Capture the current work area.',
        'Capture the equipment or materials area.',
        'Capture the designated site landmark.',
    ],

    // How often the opportunistic sweep (activate scheduled / expire lapsed
    // checkpoints) may run from a web request when the scheduler is not running.
    'sweep_throttle_seconds' => 15,

    // How often the employee's browser asks the server for an active checkpoint.
    'poll_seconds' => 20,

];
