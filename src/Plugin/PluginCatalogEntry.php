<?php

declare(strict_types=1);

namespace App\Plugin;

/**
 * One row from the distribution catalog "plugins" array.
 */
final class PluginCatalogEntry
{
    public function __construct(
        public readonly string $slug,
        public readonly string $downloadUrl,
        public readonly string $name,
        public readonly string $version,
        public readonly string $description,
        public readonly string $author,
        public readonly ?string $requiresCmsVersion,
    ) {
    }
}
