<?php

declare(strict_types=1);

namespace App\Media;

/**
 * Re-encodes raster uploads with modern codecs (WebP preferred) and optional downscaling.
 */
final class MediaImageCompressor
{
    /**
     * @return array{
     *     ok: true,
     *     path: string,
     *     ext: string,
     *     mime: string,
     *     width: int,
     *     height: int,
     *     changed: bool
     * }|array{ok: false, error: string}
     */
    public function optimize(string $sourcePath, string $extension): array
    {
        if (!MediaCompressionSettings::gdAvailable()) {
            return ['ok' => false, 'error' => 'Image compression requires the PHP GD extension.'];
        }

        if (!is_readable($sourcePath)) {
            return ['ok' => false, 'error' => 'Could not read the uploaded file.'];
        }

        $ext = strtolower($extension);
        if ($ext === 'gif') {
            if ($this->isAnimatedGif($sourcePath)) {
                return ['ok' => false, 'error' => 'Animated GIF is kept as-is.'];
            }
        }

        $raw = @file_get_contents($sourcePath);
        if ($raw === false || $raw === '') {
            return ['ok' => false, 'error' => 'Could not read the uploaded file.'];
        }

        $im = @imagecreatefromstring($raw);
        if ($im === false) {
            return ['ok' => false, 'error' => 'The file is not a valid image.'];
        }

        $srcW = imagesx($im);
        $srcH = imagesy($im);
        if ($srcW < 1 || $srcH < 1) {
            imagedestroy($im);

            return ['ok' => false, 'error' => 'Invalid image dimensions.'];
        }

        $maxEdge = MediaCompressionSettings::maxEdgePx();
        $dstW = $srcW;
        $dstH = $srcH;
        if ($srcW > $maxEdge || $srcH > $maxEdge) {
            if ($srcW >= $srcH) {
                $dstW = $maxEdge;
                $dstH = (int) max(1, round($srcH * ($maxEdge / $srcW)));
            } else {
                $dstH = $maxEdge;
                $dstW = (int) max(1, round($srcW * ($maxEdge / $srcH)));
            }
        }

        $needsResize = $dstW !== $srcW || $dstH !== $srcH;
        $working = $im;
        if ($needsResize) {
            $scaled = $this->resizeImage($im, $srcW, $srcH, $dstW, $dstH, $ext !== 'jpeg' && $ext !== 'jpg');
            imagedestroy($im);
            if ($scaled === false) {
                return ['ok' => false, 'error' => 'Could not resize the image.'];
            }
            $working = $scaled;
        }

        $originalSize = @filesize($sourcePath);
        if ($originalSize === false) {
            $originalSize = strlen($raw);
        }

        $candidates = [];
        if (function_exists('imagewebp') && $ext !== 'gif') {
            $webp = $this->encodeToTemp($working, 'webp');
            if ($webp !== null) {
                $candidates[] = $webp;
            }
        }

        if ($ext === 'jpeg' || $ext === 'jpg') {
            $jpeg = $this->encodeToTemp($working, 'jpg');
            if ($jpeg !== null) {
                $candidates[] = $jpeg;
            }
        } elseif ($ext === 'png') {
            $png = $this->encodeToTemp($working, 'png');
            if ($png !== null) {
                $candidates[] = $png;
            }
        } elseif ($ext === 'webp') {
            $webpOnly = $this->encodeToTemp($working, 'webp');
            if ($webpOnly !== null) {
                $candidates[] = $webpOnly;
            }
        } elseif ($ext === 'gif') {
            $gif = $this->encodeToTemp($working, 'gif');
            if ($gif !== null) {
                $candidates[] = $gif;
            }
        }

        imagedestroy($working);

        if ($candidates === []) {
            return ['ok' => false, 'error' => 'Could not compress the image.'];
        }

        usort($candidates, static fn (array $a, array $b): int => $a['size'] <=> $b['size']);
        $best = $candidates[0];
        foreach ($candidates as $candidate) {
            if ($candidate['path'] !== $best['path']) {
                @unlink($candidate['path']);
            }
        }

        $changed = $best['size'] < $originalSize
            || $best['ext'] !== $ext
            || $needsResize;

        if (!$changed) {
            @unlink($best['path']);

            return [
                'ok' => true,
                'path' => $sourcePath,
                'ext' => $ext,
                'mime' => $this->mimeForExt($ext),
                'width' => $srcW,
                'height' => $srcH,
                'changed' => false,
            ];
        }

        if (!@rename($best['path'], $sourcePath)) {
            if (!@copy($best['path'], $sourcePath)) {
                @unlink($best['path']);

                return ['ok' => false, 'error' => 'Could not save the compressed image.'];
            }
            @unlink($best['path']);
        }

        $verify = MediaStorage::verifyRasterImageAtPath($sourcePath, $best['ext'], PHP_INT_MAX);
        if ($verify['ok'] !== true) {
            return ['ok' => false, 'error' => $verify['error']];
        }

        return [
            'ok' => true,
            'path' => $sourcePath,
            'ext' => $best['ext'],
            'mime' => (string) $verify['mime'],
            'width' => (int) $verify['width'],
            'height' => (int) $verify['height'],
            'changed' => true,
        ];
    }

    /**
     * @return \GdImage|false
     */
    private function resizeImage(\GdImage $im, int $srcW, int $srcH, int $dstW, int $dstH, bool $preserveAlpha): \GdImage|false
    {
        $dst = imagecreatetruecolor($dstW, $dstH);
        if ($dst === false) {
            return false;
        }

        if ($preserveAlpha) {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
            imagefilledrectangle($dst, 0, 0, $dstW, $dstH, $transparent);
            imagealphablending($dst, true);
        } else {
            imagealphablending($dst, true);
            $white = imagecolorallocate($dst, 255, 255, 255);
            imagefilledrectangle($dst, 0, 0, $dstW, $dstH, $white);
        }

        if (!imagecopyresampled($dst, $im, 0, 0, 0, 0, $dstW, $dstH, $srcW, $srcH)) {
            imagedestroy($dst);

            return false;
        }

        return $dst;
    }

    /**
     * @return array{path: string, ext: string, mime: string, size: int}|null
     */
    private function encodeToTemp(\GdImage $im, string $format): ?array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'cmsc');
        if ($tmp === false) {
            return null;
        }

        $ok = match ($format) {
            'webp' => function_exists('imagewebp') && @imagewebp($im, $tmp, MediaCompressionSettings::webpQuality()),
            'jpg' => @imagejpeg($im, $tmp, MediaCompressionSettings::jpegQuality()),
            'png' => @imagepng($im, $tmp, 8),
            'gif' => @imagegif($im, $tmp),
            default => false,
        };

        if (!$ok || !is_file($tmp)) {
            @unlink($tmp);

            return null;
        }

        $size = @filesize($tmp);
        if ($size === false || $size < 1) {
            @unlink($tmp);

            return null;
        }

        return [
            'path' => $tmp,
            'ext' => $format === 'jpg' ? 'jpg' : $format,
            'mime' => $this->mimeForExt($format === 'jpg' ? 'jpg' : $format),
            'size' => (int) $size,
        ];
    }

    private function mimeForExt(string $ext): string
    {
        return match (strtolower($ext)) {
            'webp' => 'image/webp',
            'png' => 'image/png',
            'gif' => 'image/gif',
            default => 'image/jpeg',
        };
    }

    private function isAnimatedGif(string $path): bool
    {
        $chunk = @file_get_contents($path, false, null, 0, 512 * 1024);
        if ($chunk === false || $chunk === '') {
            return false;
        }

        return substr_count($chunk, "\x00\x21\xF9\x04") > 1;
    }
}
