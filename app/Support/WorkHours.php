<?php

namespace App\Support;

/**
 * Small, framework-free helpers for turning raw worked minutes into the
 * regular / overtime split the company uses.
 *
 * Rule: worked time up to the standard workday (8h by default) is regular;
 * everything beyond it is overtime. This is what keeps long or overnight
 * technical shifts (e.g. a 5:30 AM time-in, 3:50 AM time-out the next day)
 * from being counted as one confusing block — the first 8 hours are regular
 * and the remainder is overtime.
 */
class WorkHours
{
    /** Standard paid workday, in minutes (regular time cap). */
    public static function standardMinutes(): int
    {
        return (int) round((float) config('payroll.standard_workday_hours', 8) * 60);
    }

    /** Worked minutes in a day at/above which HR verification kicks in. 0 disables it. */
    public static function verificationThresholdMinutes(): int
    {
        return (int) round((float) config('payroll.ot_verification_hours', 13) * 60);
    }

    /**
     * A day this long is unusual (13h+ by default): it must be verified by HR
     * before the overtime is trusted. Also catches a forgotten clock-out.
     */
    public static function needsVerification(int $minutes): bool
    {
        $threshold = self::verificationThresholdMinutes();

        return $threshold > 0 && $minutes >= $threshold;
    }

    /**
     * Split a day's total worked minutes into ['regular' => …, 'overtime' => …].
     *
     * On a rest day (weekend), the working week is Mon–Fri, so every worked
     * minute is overtime — there is no regular time to earn. On a normal
     * workday, the first 8 hours are regular and the remainder is overtime.
     */
    public static function split(int $minutes, bool $restDay = false): array
    {
        $minutes = max(0, $minutes);

        if ($restDay) {
            return ['regular' => 0, 'overtime' => $minutes];
        }

        $standard = self::standardMinutes();

        return [
            'regular' => min($minutes, $standard),
            'overtime' => max(0, $minutes - $standard),
        ];
    }

    /** Human label like "8h 30m" (or "0h 0m"). */
    public static function label(int $minutes): string
    {
        $minutes = max(0, $minutes);

        return intdiv($minutes, 60) . 'h ' . ($minutes % 60) . 'm';
    }
}
