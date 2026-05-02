<?php

declare(strict_types=1);

namespace App\Media;

/**
 * Builds cached, downscaled raster derivatives under storage/cache/media-rs/ for public /media-rs URLs.
 */
final class MediaDerivativeService
{
    private const MAX_SOURCE_BYTES = 40_000_000;

    public function __construct(
        private readonly string $projectRoot,
        private readonly MediaRepository $mediaRepo,
        private readonly MediaUrlHelper $mediaUrls,
    ) {
    }

    /**
     * @return array{path: string, mime: string}|null Absolute cache path and MIME type, or null to fall back to original
     */
    public function derivativeFile(int $mediaId, int $width, bool $preferWebp): ?array
    {
        if ($mediaId < 1 || !MediaDerivativeWidths::isAllowed($width)) {
            return null;
        }

        $media = $this->mediaRepo->findById($mediaId);
        if ($media === null || !$media->isImage()) {
            return null;
        }

        $webPath = $this->mediaUrls->pathForMedia($media);
        if ($webPath === '' || !MediaStorage::isSafeManagedWebPath($webPath)) {
            return null;
        }

        $absSrc = $this->projectRoot . '/public' . $webPath;
        if (!is_file($absSrc) || !is_readable($absSrc)) {
            return null;
        }

        $srcSize = @filesize($absSrc);
        if ($srcSize === false || $srcSize > self::MAX_SOURCE_BYTES) {
            return null;
        }

        $srcMtime = @filemtime($absSrc);
        if ($srcMtime === false) {
            $srcMtime = 0;
        }

        if (!extension_loaded('gd')) {
            return null;
        }

        $useWebp = $preferWebp && function_exists('imagewebp');
        $ext = $useWebp ? 'webp' : 'jpg';
        $mime = $useWebp ? 'image/webp' : 'image/jpeg';

        $cacheDir = $this->projectRoot . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'media-rs';
        if (!is_dir($cacheDir) && !@mkdir($cacheDir, 0755, true) && !is_dir($cacheDir)) {
            return null;
        }

        $cacheName = $mediaId . '-' . $width . '-' . $srcMtime . '.' . $ext;
        $cachePath = $cacheDir . DIRECTORY_SEPARATOR . $cacheName;

        if (is_file($cachePath) && is_readable($cachePath)) {
            return ['path' => $cachePath, 'mime' => $mime];
        }

        $raw = @file_get_contents($absSrc);
        if ($raw === false || $raw === '') {
            return null;
        }

        $im = @imagecreatefromstring($raw);
        if ($im === false) {
            return null;
        }

        $srcW = imagesx($im);
        $srcH = imagesy($im);

        if ($srcW <= $width) {
            $newW = $srcW;
            $newH = $srcH;
        } else {
            $newW = $width;
            $newH = (int) max(1, round($srcH * ($width / $srcW)));
        }

        $dst = imagecreatetruecolor($newW, $newH);
        if ($dst === false) {
            imagedestroy($im);

            return null;
        }

        if ($useWebp) {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
            imagefilledrectangle($dst, 0, 0, $newW, $newH, $transparent);
            imagealphablending($dst, true);
        } else {
            imagealphablending($dst, true);
            $white = imagecolorallocate($dst, 255, 255, 255);
            imagefilledrectangle($dst, 0, 0, $newW, $newH, $white);
        }

        imagecopyresampled($dst, $im, 0, 0, 0, 0, $newW, $newH, $srcW, $srcH);
        imagedestroy($im);

        $tmp = $cachePath . '.tmp.' . bin2hex(random_bytes(4));
        $ok = false;
        if ($useWebp) {
            $ok = @imagewebp($dst, $tmp, 82);
        } else {
            $ok = @imagejpeg($dst, $tmp, 86);
        }
        imagedestroy($dst);

        if (!$ok || !is_file($tmp)) {
            @unlink($tmp);

            return null;
        }

        if (!@rename($tmp, $cachePath)) {
            @unlink($tmp);
            if (!is_file($cachePath)) {
                return null;
            }
        }

        return ['path' => $cachePath, 'mime' => $mime];
    }
}
