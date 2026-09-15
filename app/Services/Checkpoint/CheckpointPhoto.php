<?php

namespace App\Services\Checkpoint;

use App\Models\Checkpoint;
use App\Support\DataUrlPhoto;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\ImageManager;
use Intervention\Image\Typography\FontFactory;
use Throwable;

/**
 * Stores the live-camera checkpoint photo on the PRIVATE disk (served only
 * through a permission-checked route) with a caption strip burned in so the
 * image itself carries who/where/when/which checkpoint.
 */
class CheckpointPhoto
{
    public const DISK = 'local';

    /** @return string|null  Stored path, or null when the data URL is not a valid image. */
    public function store(Checkpoint $checkpoint, string $dataUrl, string $gpsStatus): ?string
    {
        $decoded = DataUrlPhoto::decode($dataUrl);
        if ($decoded === null) {
            return null;
        }

        $binary = $this->stamp($decoded['binary'], $checkpoint, $gpsStatus) ?? $decoded['binary'];
        $ext = $binary === $decoded['binary'] ? $decoded['ext'] : 'jpg';

        $path = "checkpoints/{$checkpoint->campaign_id}/{$checkpoint->employee_id}/"
            .now()->format('Ymd_His').'_'.$checkpoint->id.'_'.Str::random(6).'.'.$ext;
        Storage::disk(self::DISK)->put($path, $binary);

        return $path;
    }

    public function exists(Checkpoint $checkpoint): bool
    {
        return $checkpoint->photo_path && Storage::disk(self::DISK)->exists($checkpoint->photo_path);
    }

    public function response(Checkpoint $checkpoint)
    {
        abort_unless($this->exists($checkpoint), 404);

        return Storage::disk(self::DISK)->response($checkpoint->photo_path, $checkpoint->reference().'.jpg', [
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** Burn a caption strip into the bottom of the photo. Returns null if drawing fails. */
    private function stamp(string $binary, Checkpoint $checkpoint, string $gpsStatus): ?string
    {
        try {
            $cp = $checkpoint->loadMissing(['employee', 'site', 'campaign']);
            $img = (new ImageManager(new Driver))->read($binary);
            $w = $img->width();
            $h = $img->height();
            $font = resource_path('fonts/Lato-Regular.ttf');
            $size = max(14, (int) round($w / 42));
            $lineH = (int) round($size * 1.45);
            $lines = [
                ($cp->employee?->full_name ?? 'Employee').' · '.($cp->site?->name ?? 'Site'),
                now()->format('D, M j, Y g:i:s A').' · '.$cp->reference(),
                'GPS: '.$gpsStatus.' · '.($cp->campaign?->instruction ?? ''),
            ];
            $stripH = $lineH * count($lines) + $lineH;

            $img->drawRectangle(function ($r) use ($w, $h, $stripH) {
                $r->at(0, $h - $stripH);
                $r->size($w, $stripH);
                $r->background('rgba(0,0,0,0.6)');
            });
            $y = $h - $stripH + (int) ($lineH * 0.6);
            foreach ($lines as $line) {
                $img->text($line, (int) ($size * 0.8), $y, function (FontFactory $f) use ($font, $size) {
                    $f->filename($font);
                    $f->size($size);
                    $f->color('#ffffff');
                    $f->align('left', 'top');
                });
                $y += $lineH;
            }

            return (string) $img->encode(new JpegEncoder(82));
        } catch (Throwable) {
            return null;
        }
    }
}
