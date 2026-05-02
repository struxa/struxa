<?php

declare(strict_types=1);

namespace App\Media;

use PDO;

/**
 * Single entry for public web paths used in Twig and services (defense in depth on stored paths).
 */
final class MediaUrlHelper
{
    /** @var array<int, string> */
    private array $idPathCache = [];

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function pathForId(int|string|null $id): string
    {
        if ($id === null || $id === '') {
            return '';
        }
        $n = (int) $id;
        if ($n < 1) {
            return '';
        }

        if (array_key_exists($n, $this->idPathCache)) {
            return $this->idPathCache[$n];
        }

        $repo = new MediaRepository($this->pdo);
        $m = $repo->findById($n);
        $path = $this->pathForMedia($m);
        $this->idPathCache[$n] = $path;

        return $path;
    }

    public function pathForMedia(?Media $media): string
    {
        if ($media === null) {
            return '';
        }
        $path = trim($media->path);
        if ($path === '') {
            return '';
        }
        // Legacy rows sometimes omit the leading slash; normalize before the safe-path check.
        if (!str_starts_with($path, '/') && preg_match('#^uploads/#', $path) === 1) {
            $path = '/' . $path;
        }

        return MediaStorage::isSafeManagedWebPath($path) ? $path : '';
    }
}
