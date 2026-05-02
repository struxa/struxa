<?php

declare(strict_types=1);

namespace App\Cache;

use App\Filesystem\SafeDirectoryRemoval;
use App\Theme\ThemeManager;

/**
 * Clears public HTTP response cache and internal Twig/global data cache.
 */
final class StorefrontCacheInvalidator
{
    public function __construct(
        private readonly CacheManager $cacheManager,
        private readonly ?ThemeManager $themeManager = null,
    ) {
    }

    public function flushPublicResponses(): void
    {
        $this->cacheManager->publicResponses()->clear();
    }

    public function flushInternalData(): void
    {
        $this->cacheManager->internal()->clear();
    }

    public function flushAll(): void
    {
        $this->themeManager?->clearDiscoverCache();
        $this->flushPublicResponses();
        $this->flushInternalData();
        $this->flushThemeCssMinDisk();
    }

    /**
     * Drops disk cache for on-the-fly minified theme CSS (Admin → Performance toggle).
     */
    public function flushThemeCssMinDisk(): void
    {
        $base = $this->cacheManager->storageRoot();
        $dir = $base . DIRECTORY_SEPARATOR . 'theme-css-min';
        if (!is_dir($dir)) {
            return;
        }
        SafeDirectoryRemoval::removeIfInside($dir, $base);
    }
}
