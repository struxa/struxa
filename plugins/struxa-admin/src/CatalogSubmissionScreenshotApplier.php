<?php

declare(strict_types=1);

namespace StruxaAdmin;

use App\Media\MediaRepository;
use App\Media\MediaStorage;

/**
 * Copies a media-library image into catalog submission screenshot storage.
 */
final class CatalogSubmissionScreenshotApplier
{
    public function __construct(
        private readonly ScreenshotStorage $screenshots,
        private readonly MediaRepository $media,
        private readonly string $projectRoot,
    ) {
    }

    /**
     * @return array{ok: true, relative_path: string}|array{ok: false, error: string}
     */
    public function applyFromMediaId(int $mediaId, string $slug, string $kind): array
    {
        if ($mediaId < 1) {
            return ['ok' => false, 'error' => 'Choose an image from the media library.'];
        }

        $media = $this->media->findById($mediaId);
        if ($media === null || !$media->isImage()) {
            return ['ok' => false, 'error' => 'Media item not found or is not an image.'];
        }

        if (!MediaStorage::isSafeManagedWebPath($media->path)) {
            return ['ok' => false, 'error' => 'Media file path is not valid for catalog screenshots.'];
        }

        $absolute = $this->projectRoot . '/public' . $media->path;
        if (!is_readable($absolute)) {
            return ['ok' => false, 'error' => 'Media file is missing on disk.'];
        }

        return $this->screenshots->storeFromPath($absolute, $slug, $kind);
    }

    public function deleteStoredFile(?string $relativePath): void
    {
        $absolute = $this->screenshots->absolutePath($relativePath);
        if ($absolute !== null && is_file($absolute)) {
            @unlink($absolute);
        }
    }
}
