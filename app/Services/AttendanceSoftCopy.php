<?php

namespace App\Services;

use App\Models\AttendanceLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Drivers\Gd\FontProcessor;
use Intervention\Image\Encoders\PngEncoder;
use Intervention\Image\Geometry\Factories\RectangleFactory;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;
use Intervention\Image\Typography\Font;
use Intervention\Image\Typography\FontFactory;

/**
 * Generates a downloadable proof-of-attendance image (PNG) for a single
 * Time In or Time Out punch — one soft copy per punch.
 */
class AttendanceSoftCopy
{
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

    /**
     * Render the soft copy and return raw PNG bytes.
     *
     * TimeMark-style: the captured selfie fills the frame and the evidence is
     * drawn over it — map thumbnail (with the geofence) top-left, the
     * Time In/Out badge, time, date, site address and verification line
     * bottom-left, the reference along the right edge and the company mark
     * bottom-right.
     */
    public function png(AttendanceLog $log): string
    {
        $isIn = $log->log_type === 'time_in';
        $employee = $log->employee;
        $font = resource_path('fonts/Lato-Regular.ttf');

        // Brite-Tech palette (matches the app: cyan brand + coral accent)
        $accent = '#ea6c44';
        $cyan = '#7ec8e3';

        $m = new ImageManager(new Driver());

        // ── Base: the selfie itself ────────────────────────────────────
        $photoAbs = $log->photo_path ? Storage::disk('public')->path($log->photo_path) : null;
        if ($photoAbs && is_file($photoAbs)) {
            $img = $m->decodePath($photoAbs)->scaleDown(1200, 1600);
            // Tiny captures (old low-res uploads, test fixtures) are upscaled so
            // the overlay has room to lay out.
            if (min($img->width(), $img->height()) < 720) {
                $img = $img->scale(width: $img->width() >= $img->height() ? 1440 : 1080);
            }
        } else {
            $img = $m->createImage(1080, 1440)->fill('#1c1c1e');
            $img->text('No photo captured', 540, 720, function (FontFactory $f) use ($font) {
                $f->filename($font);
                $f->size(40);
                $f->color('#94a3b8');
                $f->align('center', 'center');
            });
        }
        $W = $img->width();
        $H = $img->height();
        // Everything below is designed on a 1080-wide portrait frame and
        // scaled by $u so a landscape webcam shot lays out the same way.
        $u = min($W, $H) / 1080;
        $px = fn (float $v): int => (int) round($v * $u);
        $measure = fn (string $text, float $size) => (new FontProcessor())->boxSize($text, new Font($font, $size * $u));

        // ── Scrims so the overlays read on any background ─────────────
        $topScrim = (int) ($H * 0.22);
        for ($i = 0; $i < 24; $i++) {
            $y0 = (int) ($topScrim * $i / 24);
            $y1 = (int) ($topScrim * ($i + 1) / 24);
            $alpha = 0.45 * (1 - $i / 24) ** 1.4;
            $img->drawRectangle(function (RectangleFactory $r) use ($y0, $y1, $W, $alpha) {
                $r->at(0, $y0);
                $r->size($W, max(1, $y1 - $y0));
                $r->background("rgba(0,0,0,{$alpha})");
            });
        }
        $scrimTop = (int) ($H * 0.5);
        $steps = 48;
        for ($i = 0; $i < $steps; $i++) {
            $y0 = $scrimTop + (int) (($H - $scrimTop) * $i / $steps);
            $y1 = $scrimTop + (int) (($H - $scrimTop) * ($i + 1) / $steps);
            $alpha = 0.78 * ($i / $steps) ** 1.6;
            $img->drawRectangle(function (RectangleFactory $r) use ($y0, $y1, $W, $alpha) {
                $r->at(0, $y0);
                $r->size($W, max(1, $y1 - $y0));
                $r->background("rgba(0,0,0,{$alpha})");
            });
        }

        // ── Map thumbnail with the geofence, top-left ──────────────────
        $lat = $log->latitude !== null ? (float) $log->latitude : null;
        $lng = $log->longitude !== null ? (float) $log->longitude : null;
        $site = $log->site ?? null;
        $mapSize = $px(300);
        $mapX = $px(36);
        $mapY = $px(36);
        $img->drawRectangle(function (RectangleFactory $r) use ($mapX, $mapY, $mapSize, $px) {
            $r->at($mapX - $px(6), $mapY - $px(6));
            $r->size($mapSize + $px(12), $mapSize + $px(12));
            $r->background('#ffffff');
        });
        $fence = $site ? [(float) $site->latitude, (float) $site->longitude, (int) $site->geofence_radius_m] : null;
        $map = ($lat !== null && $lng !== null) ? $this->mapImage($m, $lat, $lng, $mapSize, $mapSize, 16, $fence) : null;
        if ($map) {
            $img->insert($map, $mapX, $mapY);
        } else {
            $img->drawRectangle(function (RectangleFactory $r) use ($mapX, $mapY, $mapSize) {
                $r->at($mapX, $mapY);
                $r->size($mapSize, $mapSize);
                $r->background('#e5e7eb');
            });
            $img->text($lat !== null ? 'Map unavailable' : 'No location', (int) ($mapX + $mapSize / 2), (int) ($mapY + $mapSize / 2), function (FontFactory $f) use ($font, $px) {
                $f->filename($font);
                $f->size($px(22));
                $f->color('#6b7280');
                $f->align('center', 'center');
            });
        }

        // Coordinates caption inside the map thumbnail
        if ($lat !== null && $lng !== null) {
            $capH = $px(34);
            $img->drawRectangle(function (RectangleFactory $r) use ($mapX, $mapY, $mapSize, $capH) {
                $r->at($mapX, $mapY + $mapSize - $capH);
                $r->size($mapSize, $capH);
                $r->background('rgba(0,0,0,0.62)');
            });
            $caption = number_format($lat, 5) . ', ' . number_format($lng, 5)
                . ($log->gps_accuracy_m !== null ? '  ±' . number_format((float) $log->gps_accuracy_m) . 'm' : '');
            $img->text($caption, (int) ($mapX + $mapSize / 2), (int) ($mapY + $mapSize - $capH / 2), function (FontFactory $f) use ($font, $px) {
                $f->filename($font);
                $f->size($px(18));
                $f->color('#ffffff');
                $f->align('center', 'center');
            });
        }

        // ── Bottom-left block, laid out upwards from the bottom margin ──
        $left = $px(48);
        $textW = $W - $left - $px(120); // keep clear of the vertical reference on the right
        $shadow = fn (FontFactory $f) => $f->stroke('#000000', max(1, $px(2)));

        // Verification line
        $verdict = $log->location_status
            ? GeofenceService::label($log->location_status)
            : ((bool) $log->within_geofence ? 'Inside geofence' : 'Outside geofence');
        $ok = in_array($log->location_status, [GeofenceService::VERIFIED_LOCATION, GeofenceService::AUTHORIZED_ALTERNATE_LOCATION], true)
            || ($log->location_status === null && $log->within_geofence);
        if ($log->location_verification_status === 'approved') {
            $verdict .= ' · approved by HR';
            $ok = true;
        } elseif ($log->location_verification_status === 'rejected') {
            $verdict .= ' · rejected by HR';
            $ok = false;
        } elseif ($log->location_verification_status === 'pending') {
            $verdict .= ' · pending HR review';
        }
        $verifyLine = $verdict . ($site ? ' · ' . $site->name : '')
            . ($log->distance_m !== null && ! $log->within_geofence ? ' · ' . number_format((float) $log->distance_m) . ' m from nearest site' : '');
        $y = $H - $px(56);
        $img->drawCircle(function ($c) use ($left, $y, $px, $ok) {
            $c->at($left + $px(9), $y - $px(11));
            $c->radius($px(9));
            $c->background($ok ? '#6ee7b7' : '#fda4af');
        });
        $img->text($verifyLine, $left + $px(28), $y, function (FontFactory $f) use ($font, $px, $ok, $shadow) {
            $f->filename($font);
            $f->size($px(26));
            $f->color($ok ? '#6ee7b7' : '#fda4af');
            $f->align('left', 'bottom');
            $shadow($f);
        });

        // Address (wrapped) and date, with a coral rule on the left
        $address = $site ? trim($site->name . ($site->address ? ' · ' . $site->address : '')) : 'No registered work site matched';
        $addrLines = $this->wrapLines($address, $px(30), $textW - $px(24), $font);
        $lineH = $px(38);
        $y -= $px(34) + $lineH * count($addrLines);
        $blockBottom = $y + $lineH * count($addrLines);
        foreach ($addrLines as $i => $line) {
            $img->text($line, $left + $px(20), $y + $lineH * ($i + 1) - $px(6), function (FontFactory $f) use ($font, $px, $shadow) {
                $f->filename($font);
                $f->size($px(30));
                $f->color('#ffffff');
                $f->align('left', 'bottom');
                $shadow($f);
            });
        }
        $y -= $px(46);
        $img->text($log->logged_at->format('D, M j, Y'), $left + $px(20), $y + $px(34), function (FontFactory $f) use ($font, $px, $shadow) {
            $f->filename($font);
            $f->size($px(34));
            $f->color('#ffffff');
            $f->align('left', 'bottom');
            $shadow($f);
        });
        $ruleTop = $y - $px(4);
        $img->drawRectangle(function (RectangleFactory $r) use ($left, $ruleTop, $blockBottom, $px, $accent) {
            $r->at($left, $ruleTop);
            $r->size($px(8), $blockBottom - $ruleTop);
            $r->background($accent);
        });

        // Badge: [ Time In ][ 2:24 PM ][ On Time ]
        $badgeH = $px(76);
        $y = $ruleTop - $px(28) - $badgeH;
        $label = $isIn ? 'Time In' : 'Time Out';
        $labelW = $measure($label, 40)->width() + $px(44);
        $img->drawRectangle(function (RectangleFactory $r) use ($left, $y, $labelW, $badgeH, $accent) {
            $r->at($left, $y);
            $r->size($labelW, $badgeH);
            $r->background($accent);
        });
        $img->text($label, (int) ($left + $labelW / 2), (int) ($y + $badgeH / 2), function (FontFactory $f) use ($font, $px) {
            $f->filename($font);
            $f->size($px(40));
            $f->color('#ffffff');
            $f->align('center', 'center');
        });
        $time = $log->logged_at->format('g:i');
        $ampm = $log->logged_at->format('A');
        $timeW = $measure($time, 56)->width() + $measure($ampm, 22)->width() + $px(56);
        $img->drawRectangle(function (RectangleFactory $r) use ($left, $labelW, $y, $timeW, $badgeH) {
            $r->at($left + $labelW, $y);
            $r->size($timeW, $badgeH);
            $r->background('#ffffff');
        });
        $img->text($time, $left + $labelW + $px(20), (int) ($y + $badgeH / 2 + $px(2)), function (FontFactory $f) use ($font, $px) {
            $f->filename($font);
            $f->size($px(56));
            $f->color('#111827');
            $f->align('left', 'center');
        });
        $img->text($ampm, $left + $labelW + $timeW - $px(20), (int) ($y + $badgeH / 2 - $px(10)), function (FontFactory $f) use ($font, $px) {
            $f->filename($font);
            $f->size($px(22));
            $f->color('#35748a');
            $f->align('right', 'center');
        });
        // Employee name
        $y -= $px(24);
        $img->text($employee?->full_name ?? '—', $left, $y, function (FontFactory $f) use ($font, $px, $shadow) {
            $f->filename($font);
            $f->size($px(64));
            $f->color('#ffffff');
            $f->align('left', 'bottom');
            $shadow($f);
        });
        if ($employee?->employee_no) {
            $nameW = $measure($employee->full_name, 64)->width();
            $img->text($employee->employee_no, $left + $nameW + $px(18), $y - $px(8), function (FontFactory $f) use ($font, $px, $cyan, $shadow) {
                $f->filename($font);
                $f->size($px(24));
                $f->color($cyan);
                $f->align('left', 'bottom');
                $shadow($f);
            });
        }

        // ── Reference along the right edge ─────────────────────────────
        $ref = $this->reference($log) . '  ·  Brite-Tech Verified  ·  generated ' . now()->format('M j, Y g:i A');
        $img->text($ref, $W - $px(52), $H - $px(56), function (FontFactory $f) use ($font, $px, $shadow) {
            $f->filename($font);
            $f->size($px(22));
            $f->color('#e5e7eb');
            $f->angle(-90);
            $f->align('left', 'top');
            $shadow($f);
        });

        // ── Company mark, top-right (the PNG is transparent) ───────────
        $logoPath = public_path('images/brite-logo.png');
        if (is_file($logoPath)) {
            $logo = $m->decodePath($logoPath)->scale(width: $px(240));
            $logoX = $W - $px(36) - $logo->width();
            $logoY = $px(36);
            $img->insert($logo, $logoX, $logoY);
            $img->text('Proof of attendance', $W - $px(36), $logoY + $logo->height() + $px(30), function (FontFactory $f) use ($font, $px, $shadow) {
                $f->filename($font);
                $f->size($px(20));
                $f->color('#f3f4f6');
                $f->align('right', 'bottom');
                $shadow($f);
            });
        }

        return (string) $img->encode(new PngEncoder());
    }

    /** Greedy word-wrap using real glyph widths. */
    private function wrapLines(string $text, float $size, int $maxWidth, string $font): array
    {
        $proc = new FontProcessor();
        $fontObj = new Font($font, $size);
        $lines = [];
        $current = '';
        foreach (preg_split('/\s+/', trim($text)) ?: [] as $word) {
            $try = $current === '' ? $word : "{$current} {$word}";
            if ($current !== '' && $proc->boxSize($try, $fontObj)->width() > $maxWidth) {
                $lines[] = $current;
                $current = $word;
            } else {
                $current = $try;
            }
        }
        if ($current !== '') {
            $lines[] = $current;
        }

        return $lines ?: [''];
    }

    /**
     * Build a static map image (OpenStreetMap tiles) centred on the given
     * coordinate with a red pin at the middle. Returns null if tiles can't be
     * fetched (e.g. offline) so the caller can fall back gracefully. Results are
     * cached on the public disk to avoid re-fetching tiles on every download.
     */
    private function mapImage(ImageManager $m, float $lat, float $lng, int $w, int $h, int $zoom = 16, ?array $fence = null): ?ImageInterface
    {
        $disk = Storage::disk('public');
        $cacheKey = 'attendance-maps/' . md5("{$lat},{$lng},{$zoom},{$w}x{$h}," . json_encode($fence)) . '.png';
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

            // Geofence circle of the matched site (translucent brand cyan)
            if ($fence) {
                [$fLat, $fLng, $radiusM] = $fence;
                $fx = ($fLng + 180) / 360 * $n * 256 - $ox;
                $fLatRad = deg2rad($fLat);
                $fy = (1 - log(tan($fLatRad) + 1 / cos($fLatRad)) / M_PI) / 2 * $n * 256 - $oy;
                $metersPerPx = 156543.03392 * cos($fLatRad) / $n;
                $rPx = max(4, (int) round($radiusM / $metersPerPx));
                $map->drawCircle(function ($c) use ($fx, $fy, $rPx) {
                    $c->at((int) round($fx), (int) round($fy));
                    $c->radius($rPx);
                    $c->background('rgba(74, 155, 181, 0.22)');
                    $c->border('#4a9bb5', 2);
                });
                $map->drawCircle(function ($c) use ($fx, $fy) {
                    $c->at((int) round($fx), (int) round($fy));
                    $c->radius(5);
                    $c->background('#2563eb');
                    $c->border('#ffffff', 2);
                });
            }

            // Coral drop-pin at the window centre (the punch)
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
                $p->background('#ea6c44');
            });
            $map->drawCircle(function ($c) use ($px, $py) {
                $c->at($px, $py - 24);
                $c->radius(11);
                $c->background('#ea6c44');
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
