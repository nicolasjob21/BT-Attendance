<?php

namespace App\Services;

use App\Models\AttendanceLog;
use Illuminate\Support\Carbon;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\PngEncoder;
use Intervention\Image\Geometry\Factories\RectangleFactory;
use Intervention\Image\ImageManager;
use Intervention\Image\Typography\FontFactory;

/**
 * Generates a downloadable proof-of-attendance image (PNG) for a single
 * Time In or Time Out punch — one soft copy per punch.
 */
class AttendanceSoftCopy
{
    private const W = 660;
    private const H = 440;

    /** Unique reference, e.g. ATT-123-IN / ATT-45-OUT. */
    public function reference(AttendanceLog $log): string
    {
        $suffix = $log->log_type === 'time_in' ? 'IN' : 'OUT';

        return "ATT-{$log->id}-{$suffix}";
    }

    /** Download filename. */
    public function filename(AttendanceLog $log): string
    {
        return $this->reference($log) . '.png';
    }

    /**
     * Attendance status for the punch, relative to the employee's schedule:
     * On Time / Late (time-in) · Overtime / Undertime / On Time (time-out) ·
     * Regular when there is no fixed schedule to compare against.
     */
    public function status(AttendanceLog $log): string
    {
        $schedule = $log->employee?->schedule;
        $date = $log->logged_at->toDateString();

        if ($log->log_type === 'time_in') {
            if (! $schedule || ! $schedule->time_in) {
                return 'Regular';
            }
            $expected = Carbon::parse("{$date} {$schedule->time_in}")
                ->addMinutes((int) ($schedule->grace_minutes ?? 0));

            return $log->logged_at->lessThanOrEqualTo($expected) ? 'On Time' : 'Late';
        }

        // time_out
        if (! $schedule || ! $schedule->time_out) {
            return 'Regular';
        }
        $expectedOut = Carbon::parse("{$date} {$schedule->time_out}");

        if ($log->logged_at->greaterThan($expectedOut)) {
            return 'Overtime';
        }

        return $log->logged_at->lessThan($expectedOut) ? 'Undertime' : 'On Time';
    }

    /** Render the soft copy and return raw PNG bytes. */
    public function png(AttendanceLog $log): string
    {
        $isIn = $log->log_type === 'time_in';
        $employee = $log->employee;
        $status = $this->status($log);
        $font = resource_path('fonts/Lato-Regular.ttf');

        // Palette (aligned with the app's cyan/coral identity)
        $ink = '#16242b';
        $muted = '#5a6b73';
        $headerBg = '#2c5f72';
        $accent = $isIn ? '#2f8f63' : '#c85f3a'; // green in / coral out
        $statusColor = match ($status) {
            'On Time', 'Regular' => '#2f8f63',
            'Overtime' => '#b45309',
            default => '#c0392b', // Late / Undertime
        };

        $m = new ImageManager(new Driver());
        $img = $m->createImage(self::W, self::H);
        $img->fill('#f5f8fa');

        // Header band
        $img->drawRectangle(function (RectangleFactory $r) use ($headerBg) {
            $r->at(0, 0);
            $r->size(self::W, 92);
            $r->background($headerBg);
        });
        $img->text('BRITE-TECH SOLUTIONS', 32, 30, function (FontFactory $f) use ($font) {
            $f->filename($font);
            $f->size(22);
            $f->color('#ffffff');
        });
        $img->text('Proof of Attendance', 32, 60, function (FontFactory $f) use ($font) {
            $f->filename($font);
            $f->size(14);
            $f->color('#b9e2ef');
        });

        // Type pill (TIME IN / TIME OUT)
        $img->drawRectangle(function (RectangleFactory $r) use ($accent) {
            $r->at(32, 120);
            $r->size(150, 38);
            $r->background($accent);
        });
        $img->text(strtoupper($isIn ? 'Time In' : 'Time Out'), 48, 129, function (FontFactory $f) use ($font) {
            $f->filename($font);
            $f->size(17);
            $f->color('#ffffff');
        });

        // Big time value
        $img->text($log->logged_at->format('g:i A'), 210, 122, function (FontFactory $f) use ($font, $ink) {
            $f->filename($font);
            $f->size(34);
            $f->color($ink);
        });

        // Field rows: label + value
        $rows = [
            ['Employee', $employee?->full_name ?? '—'],
            ['Employee ID', $employee?->employee_no ?? '—'],
            ['Date', $log->logged_at->format('l, F j, Y')],
        ];
        $y = 195;
        foreach ($rows as [$label, $value]) {
            $img->text($label, 32, $y, function (FontFactory $f) use ($font, $muted) {
                $f->filename($font);
                $f->size(14);
                $f->color($muted);
            });
            $img->text((string) $value, 210, $y - 2, function (FontFactory $f) use ($font, $ink) {
                $f->filename($font);
                $f->size(18);
                $f->color($ink);
            });
            $y += 44;
        }

        // Status row (emphasised)
        $img->text('Status', 32, $y, function (FontFactory $f) use ($font, $muted) {
            $f->filename($font);
            $f->size(14);
            $f->color($muted);
        });
        $img->text($status, 210, $y - 3, function (FontFactory $f) use ($font, $statusColor) {
            $f->filename($font);
            $f->size(20);
            $f->color($statusColor);
        });

        // Footer with reference + generated timestamp
        $img->drawRectangle(function (RectangleFactory $r) {
            $r->at(0, self::H - 46);
            $r->size(self::W, 46);
            $r->background('#eaf1f4');
        });
        $img->text('Ref: ' . $this->reference($log), 32, self::H - 32, function (FontFactory $f) use ($font, $ink) {
            $f->filename($font);
            $f->size(14);
            $f->color($ink);
        });
        $img->text('Generated ' . now()->format('M j, Y g:i A'), 380, self::H - 31, function (FontFactory $f) use ($font, $muted) {
            $f->filename($font);
            $f->size(12);
            $f->color($muted);
        });

        return (string) $img->encode(new PngEncoder());
    }
}
