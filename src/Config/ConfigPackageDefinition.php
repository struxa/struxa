<?php

declare(strict_types=1);

namespace App\Config;

/**
 * Named config package (preset scope bundle) for CMI-lite sync.
 */
final class ConfigPackageDefinition
{
    /**
     * @param list<string> $scopes
     */
    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly string $description,
        public readonly array $scopes,
        public readonly bool $includeEntriesByDefault = false,
        public readonly bool $builtIn = true,
    ) {
    }
}
