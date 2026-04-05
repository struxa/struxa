<?php

declare(strict_types=1);

namespace App\Cache;

/**
 * Namespaced file caches under storage/cache (or a custom root).
 */
final class CacheManager
{
    private readonly FileCache $fileBase;

    public function __construct(string $storageRoot)
    {
        $this->fileBase = new FileCache($storageRoot);
    }

    public function publicResponses(): FileCache
    {
        return $this->fileBase->withNamespace('public_response');
    }

    public function internal(): FileCache
    {
        return $this->fileBase->withNamespace('internal');
    }

    public function storageRoot(): string
    {
        return $this->fileBase->getBasePath();
    }
}
