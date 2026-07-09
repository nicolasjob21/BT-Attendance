<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Standard working hours per day
    |--------------------------------------------------------------------------
    |
    | Worked time up to this many hours in a day is treated as regular time.
    | Anything beyond it is counted as overtime. This is what lets the system
    | handle long or overnight technical shifts (e.g. a 5:30 AM time-in and a
    | 3:50 AM time-out the next day) without being "confused": the first 8 hours
    | are regular and the remainder is overtime, instead of one giant block.
    |
    */
    'standard_workday_hours' => (float) env('PAYROLL_STANDARD_WORKDAY_HOURS', 8),

    /*
    |--------------------------------------------------------------------------
    | Overtime verification threshold (hours)
    |--------------------------------------------------------------------------
    |
    | A day where an employee works this many hours or more is unusual, so it is
    | held for HR verification: HR must approve (or reject) it and record a
    | remark explaining the reason for the overtime before it is trusted. This
    | also catches a forgotten clock-out. Set to 0 to disable verification.
    |
    */
    'ot_verification_hours' => (float) env('PAYROLL_OT_VERIFICATION_HOURS', 13),

];
