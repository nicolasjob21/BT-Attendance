<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Decodes a base64 "data:image/..." URL captured from the live camera.
 * Shared by the clock-in selfie and checkpoint photo flows.
 */
class DataUrlPhoto
{
    /**
     * @return array{binary: string, ext: string}|null Null when not a decodable image data URL.
     */
    public static function decode(string $dataUrl): ?array
    {
        if (! Str::startsWith($dataUrl, 'data:image') || ! Str::contains($dataUrl, ',')) {
            return null;
        }

        [$meta, $content] = explode(',', $dataUrl, 2);
        $binary = base64_decode($content, true);
        if ($binary === false || $binary === '') {
            return null;
        }

        return ['binary' => $binary, 'ext' => Str::contains($meta, 'png') ? 'png' : 'jpg'];
    }
}
