<?php

declare(strict_types=1);

namespace App\Media;

use App\Settings;

/**
 * Upload-time image optimization (WebP re-encode, lossy JPEG/PNG, max edge cap).
 */
final class MediaCompressionSettings
{
    public const SETTING_KEY = 'media_upload_compress';

    public static function isEnabled(): bool
    {
        $env = $_ENV['CMS_MEDIA_UPLOAD_COMPRESS'] ?? null;
        if ($env !== null && $env !== '') {
            return self::truthy((string) $env);
        }

        return Settings::get(self::SETTING_KEY, '0') === '1';
    }

    public static function maxEdgePx(): int
    {
        $n = (int) ($_ENV['CMS_MEDIA_COMPRESS_MAX_EDGE'] ?? 3840);

        return max(640, min(8192, $n));
    }

    public static function webpQuality(): int
    {
        $n = (int) ($_ENV['CMS_MEDIA_WEBP_QUALITY'] ?? 80);

        return max(50, min(95, $n));
    }

    public static function jpegQuality(): int
    {
        $n = (int) ($_ENV['CMS_MEDIA_JPEG_QUALITY'] ?? 85);

        return max(60, min(95, $n));
    }

    public static function gdAvailable(): bool
    {
        return self::capabilities()['available'];
    }

    /**
     * @return array{
     *     available: bool,
     *     gd_loaded: bool,
     *     can_encode: bool,
     *     webp_encode: bool,
     *     hint: string
     * }
     */
    public static function capabilities(): array
    {
        $gdLoaded = extension_loaded('gd');
        $canEncode = function_exists('imagecreatefromstring')
            && (function_exists('imagejpeg') || function_exists('imagepng') || function_exists('imagewebp'));
        $webpEncode = function_exists('imagewebp');
        $available = $gdLoaded && $canEncode;

        $hint = 'Re-encodes uploads as WebP when supported, otherwise optimized JPEG/PNG.';
        if (!$gdLoaded) {
            $hint = 'PHP GD is not loaded. Install the php-gd package on the server, or rebuild the Docker image (see Dockerfile).';
        } elseif (!$canEncode) {
            $hint = 'GD is loaded but image encode functions are missing. Reinstall php-gd with JPEG/PNG/WebP support.';
        } elseif (!$webpEncode) {
            $hint = 'GD is available without WebP; uploads will be re-encoded as JPEG/PNG only. Install libwebp for WebP output.';
        }

        return [
            'available' => $available,
            'gd_loaded' => $gdLoaded,
            'can_encode' => $canEncode,
            'webp_encode' => $webpEncode,
            'hint' => $hint,
        ];
    }

    private static function truthy(string $value): bool
    {
        return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
    }
}
