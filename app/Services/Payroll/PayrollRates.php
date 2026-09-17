<?php

namespace App\Services\Payroll;

use App\Models\Setting;

/**
 * The company-wide payroll rules, all expressed as percentages / counts and
 * editable by the Super Admin on the Payroll Rates page. Every value has a
 * default so a fresh install computes the same way it always did.
 */
final class PayrollRates
{
    public const DEFAULTS = [
        'basic_cutoff_percent' => 50.0,   // % of monthly salary paid per semi-monthly cutoff
        'working_days_per_month' => 22,   // daily rate = monthly / this
        'hours_per_day' => 8,             // hourly rate = daily / this
        'allowance_percent' => 0.0,       // % of basic pay added as allowance for everyone
        'ot_regular_percent' => 125.0,    // OT premium as % of the hourly rate
        'ot_rest_day_percent' => 130.0,
        'ot_holiday_percent' => 200.0,
        'withholding_tax_percent' => 0.0, // flat % of taxable pay (gross − SSS/PhilHealth/Pag-IBIG)
        'pagibig_monthly_cap' => 200.0,   // ₱ cap on the employee's monthly Pag-IBIG share
    ];

    public const LABELS = [
        'basic_cutoff_percent' => 'Basic pay per cutoff',
        'working_days_per_month' => 'Working days per month',
        'hours_per_day' => 'Hours per day',
        'allowance_percent' => 'Allowance (of basic pay)',
        'ot_regular_percent' => 'Regular overtime',
        'ot_rest_day_percent' => 'Rest-day overtime',
        'ot_holiday_percent' => 'Holiday overtime',
        'withholding_tax_percent' => 'Withholding tax',
        'pagibig_monthly_cap' => 'Pag-IBIG monthly cap (₱)',
    ];

    public static function get(string $key): float
    {
        return (float) Setting::get('payroll.rates.'.$key, self::DEFAULTS[$key]);
    }

    public static function all(): array
    {
        return collect(self::DEFAULTS)->mapWithKeys(fn ($d, $k) => [$k => self::get($k)])->all();
    }

    public static function set(array $values): void
    {
        foreach (self::DEFAULTS as $key => $default) {
            if (array_key_exists($key, $values)) {
                Setting::set('payroll.rates.'.$key, (float) $values[$key]);
            }
        }
    }

    /** Multiplier for an OT type, e.g. 1.25 for "regular". */
    public static function otMultiplier(string $type): float
    {
        $key = match ($type) {
            'rest_day' => 'ot_rest_day_percent',
            'holiday' => 'ot_holiday_percent',
            default => 'ot_regular_percent',
        };

        return self::get($key) / 100;
    }
}
