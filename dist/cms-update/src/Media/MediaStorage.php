<?php

declare(strict_types=1);

namespace App\Media;

/**
 * Central rules for on-disk media under public/uploads (paths, safety, cleanup).
 */
final class MediaStorage
{
    public const WEB_PREFIX = '/uploads/';

    /** @var array<string, list<string>> */
    public const EXTENSION_TO_MIMES = [
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'webp' => ['image/webp'],
        'gif' => ['image/gif'],
    ];

    /**
     * Web paths we persist must stay under this prefix with no traversal or odd segments.
     */
    public static function isSafeManagedWebPath(string $path): bool
    {
        $path = trim($path);
        if ($path === '' || !str_starts_with($path, self::WEB_PREFIX)) {
            return false;
        }
        if (str_contains($path, '..') || str_contains($path, "\0") || str_contains($path, '//')) {
            return false;
        }

        return (bool) preg_match('#^/uploads/[a-zA-Z0-9_./\-]+$#', $path);
    }

    public static function uploadsRootAbsolute(string $projectRoot): string
    {
        return $projectRoot . '/public/uploads';
    }

    /**
     * After mkdir, confirms the directory for a new file is inside public/uploads.
     */
    public static function isDirectoryUnderUploads(string $projectRoot, string $absoluteDir): bool
    {
        $base = realpath(self::uploadsRootAbsolute($projectRoot));
        $dir = realpath($absoluteDir);
        if ($base === false || $dir === false) {
            return false;
        }

        return str_starts_with($dir . DIRECTORY_SEPARATOR, $base . DIRECTORY_SEPARATOR);
    }

    /**
     * Remove a managed file and prune empty parent folders up to public/uploads.
     */
    public static function unlinkManagedFile(string $projectRoot, string $webPath): void
    {
        if (!self::isSafeManagedWebPath($webPath)) {
            return;
        }

        $full = $projectRoot . '/public' . $webPath;
        if (!is_file($full)) {
            return;
        }

        $realFile = realpath($full);
        $base = realpath(self::uploadsRootAbsolute($projectRoot));
        if ($realFile === false || $base === false || !str_starts_with($realFile . DIRECTORY_SEPARATOR, $base . DIRECTORY_SEPARATOR)) {
            return;
        }

        @unlink($realFile);
        self::pruneEmptyParents(dirname($realFile), $base);
    }

    private static function pruneEmptyParents(string $dir, string $uploadsRoot): void
    {
        if ($dir === $uploadsRoot || !str_starts_with($dir . DIRECTORY_SEPARATOR, $uploadsRoot . DIRECTORY_SEPARATOR)) {
            return;
        }

        if (!is_dir($dir)) {
            return;
        }

        $items = @scandir($dir);
        if ($items === false || count($items) > 2) {
            return;
        }

        @rmdir($dir);
        self::pruneEmptyParents(dirname($dir), $uploadsRoot);
    }

    /**
     * @return array{ok: true, mime: string, width: int, height: int}|array{ok: false, error: string}
     */
    public static function verifyRasterImageAtPath(string $absolutePath, string $extension, int $maxBytes): array
    {
        if (!is_readable($absolutePath)) {
            return ['ok' => false, 'error' => 'Could not read the file.'];
        }

        $size = filesize($absolutePath);
        if ($size === false || $size > $maxBytes) {
            return ['ok' => false, 'error' => 'File is too large.'];
        }

        $ext = strtolower($extension);
        $allowed = self::EXTENSION_TO_MIMES[$ext] ?? null;
        if ($allowed === null) {
            return ['ok' => false, 'error' => 'Unsupported image type.'];
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($absolutePath);
        if ($mime === false || !in_array($mime, $allowed, true)) {
            return ['ok' => false, 'error' => 'File type does not match its extension.'];
        }

        $dims = @getimagesize($absolutePath);
        if ($dims === false) {
            return ['ok' => false, 'error' => 'The file is not a valid image.'];
        }

        $width = (int) $dims[0];
        $height = (int) $dims[1];
        if ($width < 1 || $height < 1) {
            return ['ok' => false, 'error' => 'Invalid image dimensions.'];
        }

        return ['ok' => true, 'mime' => $mime, 'width' => $width, 'height' => $height];
    }
}
