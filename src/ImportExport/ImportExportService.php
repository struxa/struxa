<?php

declare(strict_types=1);

namespace App\ImportExport;

use App\Blueprint\BlueprintImportOptions;
use App\Blueprint\BlueprintManager;
use App\Blueprint\StructureCollector;
use App\Config\ConfigPackageRegistry;

/**
 * Scoped JSON import/export (subset of blueprint payload).
 */
final class ImportExportService
{
    /** @var list<string> */
    public const SCOPES = ConfigPackageRegistry::ALL_SCOPES;

    public function __construct(
        private readonly StructureCollector $collector,
        private readonly BlueprintManager $blueprintManager,
    ) {
    }

    /**
     * @param list<string> $scopes content_types|menus|settings|entries|meta
     * @return array<string, mixed>
     */
    public function exportJson(array $scopes, bool $includeEntries, int $maxEntriesPerType = 50): array
    {
        return $this->collector->collectPartial($scopes, $includeEntries, $maxEntriesPerType);
    }

    /**
     * @param list<string> $scopes
     * @return array{errors: list<string>, warnings: list<string>, applied: list<string>}
     */
    public function importJson(array $payload, array $scopes, BlueprintImportOptions $opt): array
    {
        return $this->blueprintManager->applyPartialExport($payload, $scopes, $opt);
    }
}
