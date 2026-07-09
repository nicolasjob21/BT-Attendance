<?php

namespace App\Support;

/**
 * Turns a stream of raw clock events (time_in / time_out logs) into paired
 * work sessions. Shared by the attendance monitor and overtime calculation so
 * "what did this person actually work that day" is defined in exactly one place.
 *
 * A session is ['in' => AttendanceLog, 'out' => AttendanceLog|null]; a null out
 * means the session is still open (clocked in, not yet out).
 */
class WorkSessions
{
    /** Pair an ordered (by logged_at) collection of logs into sessions. */
    public static function pair(iterable $logs): array
    {
        $sessions = [];
        $openIn = null;

        foreach ($logs as $log) {
            if ($log->log_type === 'time_in') {
                if ($openIn) {           // clocked in twice without a clock-out
                    $sessions[] = ['in' => $openIn, 'out' => null];
                }
                $openIn = $log;
            } elseif ($openIn) {         // time_out that closes an open session
                $sessions[] = ['in' => $openIn, 'out' => $log];
                $openIn = null;
            }
            // a time_out with no open in belongs to a prior day's session — skip it
        }

        if ($openIn) {
            $sessions[] = ['in' => $openIn, 'out' => null];
        }

        return $sessions;
    }

    /** Keep only sessions whose time-in falls on the given date (Y-m-d). */
    public static function startingOn(array $sessions, string $date): array
    {
        return array_values(array_filter(
            $sessions,
            fn ($s) => $s['in']->logged_at->toDateString() === $date,
        ));
    }

    /** Total worked minutes across the closed sessions. */
    public static function workedMinutes(array $sessions): int
    {
        $minutes = 0;
        foreach ($sessions as $s) {
            if ($s['out']) {
                // diffInMinutes is signed in Carbon 3; out always follows in here.
                $minutes += (int) round($s['in']->logged_at->diffInMinutes($s['out']->logged_at));
            }
        }

        return $minutes;
    }

    /** True when any session is still open (no clock-out). */
    public static function hasOpen(array $sessions): bool
    {
        foreach ($sessions as $s) {
            if (! $s['out']) {
                return true;
            }
        }

        return false;
    }

    /** The day's closing time-out log (last closed session's out), or null. */
    public static function closingOut(array $sessions)
    {
        $out = null;
        foreach ($sessions as $s) {
            if ($s['out']) {
                $out = $s['out'];
            }
        }

        return $out;
    }
}
