<?php

namespace App\Services;

use App\Models\AttendanceLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\PngEncoder;
use Intervention\Image\Geometry\Factories\RectangleFactory;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;
use Intervention\Image\Typography\FontFactory;

/**
 * Generates a downloadable proof-of-attendance image (PNG) for a single
 * Time In or Time Out punch — one soft copy per punch.
 */
class AttendanceSoftCopy
{
    private const W = 700;
    private const H = 704;

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
     * Early In / On Time / Late (time-in) · Overtime / Undertime / On Time
     * (time-out) · Regular when there is no fixed schedule to compare against.
     */
    public function status(AttendanceLog $log): string
    {
        $schedule = $log->employee?->schedule;
        $date = $log->logged_at->toDateString();

        if ($log->log_type === 'time_in') {
            if (! $schedule || ! $schedule->time_in) {
                return 'Regular';
            }
            $scheduled = Carbon::parse("{$date} {$schedule->time_in}");

            // Clocking in before the scheduled start is the employee's own
            // choice and has no effect on pay — flag it purely for information.
            if ($log->logged_at->lessThan($scheduled)) {
                return 'Early In';
            }

            $expected = $scheduled->copy()
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

        // Palette (aligned with the Brite-Tech logo: blue + orange)
        $ink = '#16242b';
        $muted = '#5a6b73';
        $brandBlue = '#1e73be';
        $brandOrange = '#ef7c1f';
        $accent = $isIn ? '#2f8f63' : $brandOrange; // green in / orange out
        $statusColor = match ($status) {
            'On Time', 'Regular' => '#2f8f63',
            'Early In' => '#1e73be', // informational — no pay impact
            'Overtime' => '#b45309',
            default => '#c0392b', // Late / Undertime
        };

        $margin = 36;
        $rightX = self::W - $margin;

        $m = new ImageManager(new Driver());
        $img = $m->createImage(self::W, self::H);
        $img->fill('#ffffff');

        // Company logo in a clean white header
        $logoPath = public_path('images/brite-logo.png');
        if (is_file($logoPath)) {
            $logo = $m->decodePath($logoPath)->scale(width: 196);
            $img->insert($logo, $margin, 22);
        } else {
            // Fallback wordmark if the logo asset is missing
            $img->text('BRITE-TECH', $margin, 34, function (FontFactory $f) use ($font, $brandBlue) {
                $f->filename($font);
                $f->size(26);
                $f->color($brandBlue);
            });
        }

        // Document label (right-aligned, invoice style)
        $img->text('PROOF OF ATTENDANCE', $rightX, 32, function (FontFactory $f) use ($font, $brandBlue) {
            $f->filename($font);
            $f->size(16);
            $f->color($brandBlue);
            $f->align('right');
        });
        $img->text('Ref ' . $this->reference($log), $rightX, 58, function (FontFactory $f) use ($font, $muted) {
            $f->filename($font);
            $f->size(13);
            $f->color($muted);
            $f->align('right');
        });

        // Accent divider under the header (orange lead-in + blue band)
        $img->drawRectangle(function (RectangleFactory $r) use ($brandBlue) {
            $r->at(0, 104);
            $r->size(self::W, 4);
            $r->background($brandBlue);
        });
        $img->drawRectangle(function (RectangleFactory $r) use ($brandOrange) {
            $r->at(0, 104);
            $r->size(200, 4);
            $r->background($brandOrange);
        });

        // Captured selfie (proof photo), framed on the right
        $photoSize = 200;
        $photoX = self::W - $margin - $photoSize;
        $photoY = 140;
        $photoAbs = $log->photo_path ? Storage::disk('public')->path($log->photo_path) : null;
        // Frame / border
        $img->drawRectangle(function (RectangleFactory $r) use ($photoX, $photoY, $photoSize) {
            $r->at($photoX - 3, $photoY - 3);
            $r->size($photoSize + 6, $photoSize + 6);
            $r->background('#e6ebee');
            $r->border('#c9d4da', 1);
        });
        if ($photoAbs && is_file($photoAbs)) {
            $selfie = $m->decodePath($photoAbs)->cover($photoSize, $photoSize);
            $img->insert($selfie, $photoX, $photoY);
        } else {
            // Placeholder when no selfie was captured
            $img->text('No photo', $photoX + $photoSize / 2, $photoY + $photoSize / 2 - 8, function (FontFactory $f) use ($font, $muted) {
                $f->filename($font);
                $f->size(15);
                $f->color($muted);
                $f->align('center');
            });
        }
        $img->text('Captured selfie', $photoX + $photoSize / 2, $photoY + $photoSize + 12, function (FontFactory $f) use ($font, $muted) {
            $f->filename($font);
            $f->size(12);
            $f->color($muted);
            $f->align('center');
        });

        // Type pill (TIME IN / TIME OUT) + big time value
        $pillY = 140;
        $pillW = 118;
        $img->drawRectangle(function (RectangleFactory $r) use ($accent, $margin, $pillY, $pillW) {
            $r->at($margin, $pillY);
            $r->size($pillW, 34);
            $r->background($accent);
        });
        $img->text(strtoupper($isIn ? 'Time In' : 'Time Out'), $margin + $pillW / 2, $pillY + 18, function (FontFactory $f) use ($font) {
            $f->filename($font);
            $f->size(14);
            $f->color('#ffffff');
            $f->align('center');
        });
        $img->text($log->logged_at->format('g:i A'), $margin, $pillY + 82, function (FontFactory $f) use ($font, $ink) {
            $f->filename($font);
            $f->size(36);
            $f->color($ink);
        });

        // Detail rows (label + value), left column beside the photo
        $rows = [
            ['Employee', $employee?->full_name ?? '—'],
            ['Employee ID', $employee?->employee_no ?? '—'],
            ['Date', $log->logged_at->format('l, F j, Y')],
            ['Status', $status],
        ];
        $y = 252;
        $labelX = $margin;
        $valueX = $margin + 118;
        foreach ($rows as [$label, $value]) {
            $isStatus = $label === 'Status';
            $img->text($label, $labelX, $y, function (FontFactory $f) use ($font, $muted) {
                $f->filename($font);
                $f->size(13);
                $f->color($muted);
            });
            $img->text((string) $value, $valueX, $y - 2, function (FontFactory $f) use ($font, $ink, $statusColor, $isStatus) {
                $f->filename($font);
                $f->size($isStatus ? 19 : 17);
                $f->color($isStatus ? $statusColor : $ink);
            });
            $y += 44;
        }

        // Location map with a pin at the punch coordinates
        $lat = $log->latitude !== null ? (float) $log->latitude : null;
        $lng = $log->longitude !== null ? (float) $log->longitude : null;
        $mapX = $margin;
        $mapY = 450;
        $mapW = self::W - 2 * $margin;
        $mapH = 140;

        // Section divider above the location block
        $img->drawRectangle(function (RectangleFactory $r) use ($margin) {
            $r->at($margin, 414);
            $r->size(self::W - 2 * $margin, 1);
            $r->background('#e6ebee');
        });
        $img->text('LOCATION', $margin, 430, function (FontFactory $f) use ($font, $brandBlue) {
            $f->filename($font);
            $f->size(13);
            $f->color($brandBlue);
        });
        // Map frame
        $img->drawRectangle(function (RectangleFactory $r) use ($mapX, $mapY, $mapW, $mapH) {
            $r->at($mapX - 2, $mapY - 2);
            $r->size($mapW + 4, $mapH + 4);
            $r->background('#e6ebee');
            $r->border('#c9d4da', 1);
        });

        $map = ($lat !== null && $lng !== null) ? $this->mapImage($m, $lat, $lng, $mapW, $mapH) : null;
        if ($map) {
            $img->insert($map, $mapX, $mapY);
        } else {
            $img->drawRectangle(function (RectangleFactory $r) use ($mapX, $mapY, $mapW, $mapH) {
                $r->at($mapX, $mapY);
                $r->size($mapW, $mapH);
                $r->background('#eef2f4');
            });
            $img->text($lat !== null ? 'Map unavailable' : 'Location not recorded', $mapX + $mapW / 2, $mapY + $mapH / 2 - 8, function (FontFactory $f) use ($font, $muted) {
                $f->filename($font);
                $f->size(14);
                $f->color($muted);
                $f->align('center');
            });
        }

        // Caption under the map: coordinates, distance and geofence status
        if ($lat !== null && $lng !== null) {
            $coords = number_format($lat, 6) . ', ' . number_format($lng, 6);
            $site = $log->site?->name;
            $dist = $log->distance_m !== null
                ? '  ·  ' . number_format(((float) $log->distance_m) / 1000, 2) . ' km' . ($site ? ' from ' . $site : '')
                : '';
            $img->text($coords . $dist, $margin, 616, function (FontFactory $f) use ($font, $ink) {
                $f->filename($font);
                $f->size(13);
                $f->color($ink);
            });
            $inside = (bool) $log->within_geofence;
            $img->text($inside ? 'Inside geofence' : 'Outside geofence', $rightX, 616, function (FontFactory $f) use ($font, $inside) {
                $f->filename($font);
                $f->size(13);
                $f->color($inside ? '#2f8f63' : '#c0392b');
                $f->align('right');
            });
        }

        // Footer band
        $img->drawRectangle(function (RectangleFactory $r) {
            $r->at(0, self::H - 52);
            $r->size(self::W, 52);
            $r->background('#eef3f6');
        });
        $img->drawRectangle(function (RectangleFactory $r) {
            $r->at(0, self::H - 52);
            $r->size(self::W, 1);
            $r->background('#dbe3e7');
        });
        $img->text('Brite-Tech Solutions · Attendance System', $margin, self::H - 34, function (FontFactory $f) use ($font, $ink) {
            $f->filename($font);
            $f->size(13);
            $f->color($ink);
        });
        $img->text('Generated ' . now()->format('M j, Y g:i A'), $rightX, self::H - 33, function (FontFactory $f) use ($font, $muted) {
            $f->filename($font);
            $f->size(12);
            $f->color($muted);
            $f->align('right');
        });

        return (string) $img->encode(new PngEncoder());
    }

    /**
     * Build a static map image (OpenStreetMap tiles) centred on the given
     * coordinate with a red pin at the middle. Returns null if tiles can't be
     * fetched (e.g. offline) so the caller can fall back gracefully. Results are
     * cached on the public disk to avoid re-fetching tiles on every download.
     */
    private function mapImage(ImageManager $m, float $lat, float $lng, int $w, int $h, int $zoom = 16): ?ImageInterface
    {
        $disk = Storage::disk('public');
        $cacheKey = 'attendance-maps/' . md5("{$lat},{$lng},{$zoom},{$w}x{$h}") . '.png';
        if ($disk->exists($cacheKey)) {
            try {
                return $m->decodeBinary($disk->get($cacheKey));
            } catch (\Throwable) {
                // fall through and rebuild
            }
        }

        try {
            $n = 2 ** $zoom;
            // World-pixel coordinates of the centre (Web Mercator, 256px tiles)
            $cx = ($lng + 180) / 360 * $n * 256;
            $latRad = deg2rad($lat);
            $cy = (1 - log(tan($latRad) + 1 / cos($latRad)) / M_PI) / 2 * $n * 256;

            $ox = $cx - $w / 2; // window top-left in world pixels
            $oy = $cy - $h / 2;

            $map = $m->createImage($w, $h);
            $map->fill('#dfe6ea');

            $txMin = (int) floor($ox / 256);
            $txMax = (int) floor(($ox + $w - 1) / 256);
            $tyMin = (int) floor($oy / 256);
            $tyMax = (int) floor(($oy + $h - 1) / 256);

            $ctx = stream_context_create(['http' => [
                'header' => "User-Agent: BT-Attendance/1.0 (software@brite-tsi.com)\r\n",
                'timeout' => 6,
            ]]);
            $servers = ['a', 'b', 'c'];

            for ($tx = $txMin; $tx <= $txMax; $tx++) {
                for ($ty = $tyMin; $ty <= $tyMax; $ty++) {
                    if ($tx < 0 || $ty < 0 || $tx >= $n || $ty >= $n) {
                        continue;
                    }
                    $s = $servers[abs($tx + $ty) % 3];
                    $bytes = @file_get_contents("https://{$s}.tile.openstreetmap.org/{$zoom}/{$tx}/{$ty}.png", false, $ctx);
                    if ($bytes === false) {
                        return null; // network failure — caller falls back
                    }
                    $px = (int) round($tx * 256 - $ox);
                    $py = (int) round($ty * 256 - $oy);
                    $map->insert($m->decodeBinary($bytes), $px, $py);
                }
            }

            // Red drop-pin at the window centre
            $px = intdiv($w, 2);
            $py = intdiv($h, 2);
            $map->drawEllipse(function ($e) use ($px, $py) {
                $e->at($px, $py);
                $e->size(16, 6);
                $e->background('rgba(0, 0, 0, 0.25)');
            });
            $map->drawPolygon(function ($p) use ($px, $py) {
                $p->point($px - 8, $py - 17);
                $p->point($px + 8, $py - 17);
                $p->point($px, $py);
                $p->background('#c0392b');
            });
            $map->drawCircle(function ($c) use ($px, $py) {
                $c->at($px, $py - 24);
                $c->radius(11);
                $c->background('#c0392b');
                $c->border('#ffffff', 2);
            });
            $map->drawCircle(function ($c) use ($px, $py) {
                $c->at($px, $py - 24);
                $c->radius(4);
                $c->background('#ffffff');
            });

            $disk->put($cacheKey, (string) $map->encode(new PngEncoder()));

            return $map;
        } catch (\Throwable) {
            return null;
        }
    }
}
