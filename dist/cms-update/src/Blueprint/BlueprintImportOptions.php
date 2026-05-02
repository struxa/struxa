<?php

declare(strict_types=1);

namespace App\Blueprint;

final class BlueprintImportOptions
{
    public function __construct(
        public readonly bool $dryRun = false,
        /** When true, add missing rows only; existing slugs are not replaced. */
        public readonly bool $merge = true,
        public readonly bool $applyThemeFromBlueprint = false,
        public readonly bool $importContentEntries = false,
    ) {
    }
}
