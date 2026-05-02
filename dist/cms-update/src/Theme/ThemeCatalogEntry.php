<?php

declare(strict_types=1);

namespace App\Theme;

/**
 * One row from a theme catalog JSON file (remote registry).
 */
final class ThemeCatalogEntry
{
    public function __construct(
        public readonly string $slug,
        public readonly string $downloadUrl,
        public readonly string $name,
        public readonly string $version,
        public readonly string $description,
        public readonly string $author,
    ) {
    }
}
