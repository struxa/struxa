<?php

declare(strict_types=1);

namespace App\Media;

/**
 * Batch re-compress existing library images using {@see MediaImageCompressor}.
 */
final class MediaLibraryOptimizer
{
    public function __construct(
        private readonly MediaRepository $repository,
        private readonly string $projectRoot,
    ) {
    }

    /**
     * @return array{
     *     ok: true,
     *     processed: int,
     *     optimized: int,
     *     skipped: int,
     *     bytes_saved: int,
     *     errors: list<string>,
     *     next_after_id: int,
     *     done: bool
     * }|array{ok: false, error: string}
     */
    public function compressBatch(int $afterId, int $limit = 20): array
    {
        if (!MediaCompressionSettings::gdAvailable()) {
            return ['ok' => false, 'error' => MediaCompressionSettings::capabilities()['hint']];
        }

        $limit = max(1, min(50, $limit));
        $afterId = max(0, $afterId);
        $rows = $this->repository->listImagesAfterId($afterId, $limit);
        if ($rows === []) {
            return [
                'ok' => true,
                'processed' => 0,
                'optimized' => 0,
                'skipped' => 0,
                'bytes_saved' => 0,
                'errors' => [],
                'next_after_id' => $afterId,
                'done' => true,
            ];
        }

        $compressor = new MediaImageCompressor();
        $processed = 0;
        $optimized = 0;
        $skipped = 0;
        $bytesSaved = 0;
        $errors = [];
        $lastId = $afterId;

        foreach ($rows as $media) {
            ++$processed;
            $lastId = $media->id;
            if (!MediaStorage::isSafeManagedWebPath($media->path)) {
                ++$skipped;
                continue;
            }

            $absolute = $this->projectRoot . '/public' . $media->path;
            if (!is_readable($absolute)) {
                ++$skipped;
                continue;
            }

            $beforeSize = (int) (@filesize($absolute) ?: $media->fileSize);
            $result = $compressor->optimize($absolute, $media->extension);
            if (($result['ok'] ?? false) !== true) {
                ++$skipped;
                $msg = (string) ($result['error'] ?? 'Could not optimize.');
                if (count($errors) < 5) {
                    $errors[] = '#' . $media->id . ': ' . $msg;
                }
                continue;
            }

            $newExt = (string) $result['ext'];
            $newMime = (string) $result['mime'];
            $newPath = $media->path;
            $newAbsolute = $absolute;

            if ($newExt !== $media->extension) {
                $candidate = preg_replace('/\.[^.\/]+$/', '.' . $newExt, $media->path);
                if (is_string($candidate) && MediaStorage::isSafeManagedWebPath($candidate)) {
                    $candidateAbs = $this->projectRoot . '/public' . $candidate;
                    if (@rename($absolute, $candidateAbs) || @copy($absolute, $candidateAbs)) {
                        if ($candidateAbs !== $absolute && is_file($absolute)) {
                            @unlink($absolute);
                        }
                        $newPath = $candidate;
                        $newAbsolute = $candidateAbs;
                    }
                }
            }

            $afterSize = (int) (@filesize($newAbsolute) ?: $beforeSize);
            $saved = max(0, $beforeSize - $afterSize);
            if (($result['changed'] ?? false) === true) {
                ++$optimized;
                $bytesSaved += $saved;
            } else {
                ++$skipped;
            }

            $filename = basename($newPath);
            $this->repository->updateFileRecord(
                $media->id,
                $filename,
                $newMime,
                $newExt,
                $afterSize,
                (int) $result['width'],
                (int) $result['height'],
                $newPath
            );
            $this->clearDerivativeCacheForMedia($media->id);
        }

        $more = $this->repository->listImagesAfterId($lastId, 1);

        return [
            'ok' => true,
            'processed' => $processed,
            'optimized' => $optimized,
            'skipped' => $skipped,
            'bytes_saved' => $bytesSaved,
            'errors' => $errors,
            'next_after_id' => $lastId,
            'done' => $more === [],
        ];
    }

    private function clearDerivativeCacheForMedia(int $mediaId): void
    {
        $dir = $this->projectRoot . '/storage/cache/media-rs';
        if (!is_dir($dir)) {
            return;
        }
        $prefix = (string) $mediaId . '-';
        foreach (glob($dir . '/*') ?: [] as $f) {
            if (is_file($f) && str_starts_with(basename($f), $prefix)) {
                @unlink($f);
            }
        }
    }
}
