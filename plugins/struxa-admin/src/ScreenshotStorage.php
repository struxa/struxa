<?php

declare(strict_types=1);

namespace StruxaAdmin;

final class ScreenshotStorage
{
    public function __construct(
        private readonly string $pluginRoot,
    ) {
    }

    public function screenshotsDir(): string
    {
        $dir = $this->pluginRoot . '/storage/screenshots';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        return $dir;
    }

    /**
     * @param array<string, mixed> $file PSR-7 uploaded file array or $_FILES shape
     * @return array{ok: true, relative_path: string}|array{ok: false, error: string}
     */
    public function storeUpload(array $file, string $slug, string $kind): array
    {
        $err = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err === UPLOAD_ERR_NO_FILE) {
            return ['ok' => true, 'relative_path' => ''];
        }
        if ($err !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'error' => 'Screenshot upload failed.'];
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            return ['ok' => false, 'error' => 'Invalid screenshot upload.'];
        }
        $size = (int) ($file['size'] ?? 0);
        if ($size < 1 || $size > 2_500_000) {
            return ['ok' => false, 'error' => 'Screenshot must be under 2.5 MB.'];
        }
        $mime = mime_content_type($tmp) ?: '';
        $ext = match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => null,
        };
        if ($ext === null) {
            return ['ok' => false, 'error' => 'Screenshot must be JPEG, PNG, or WebP.'];
        }
        $name = $kind . '-' . $slug . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
        $dest = $this->screenshotsDir() . '/' . $name;
        if (!move_uploaded_file($tmp, $dest)) {
            return ['ok' => false, 'error' => 'Could not save screenshot.'];
        }

        return ['ok' => true, 'relative_path' => 'screenshots/' . $name];
    }

    /**
     * @return array{ok: true, relative_path: string}|array{ok: false, error: string}
     */
    public function storeFromPath(string $sourcePath, string $slug, string $kind): array
    {
        if (!is_readable($sourcePath)) {
            return ['ok' => false, 'error' => 'Could not read screenshot file.'];
        }
        $size = filesize($sourcePath);
        if ($size === false || $size < 1 || $size > 2_500_000) {
            return ['ok' => false, 'error' => 'Screenshot must be under 2.5 MB.'];
        }
        $mime = mime_content_type($sourcePath) ?: '';
        $ext = match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => null,
        };
        if ($ext === null) {
            return ['ok' => false, 'error' => 'Screenshot must be JPEG, PNG, or WebP.'];
        }
        $name = $kind . '-' . $slug . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
        $dest = $this->screenshotsDir() . '/' . $name;
        if (!copy($sourcePath, $dest)) {
            return ['ok' => false, 'error' => 'Could not save screenshot.'];
        }

        return ['ok' => true, 'relative_path' => 'screenshots/' . $name];
    }

    public function absolutePath(?string $relativePath): ?string
    {
        if ($relativePath === null || $relativePath === '') {
            return null;
        }
        $full = $this->pluginRoot . '/storage/' . ltrim($relativePath, '/');
        if (!is_file($full)) {
            return null;
        }

        return $full;
    }
}
