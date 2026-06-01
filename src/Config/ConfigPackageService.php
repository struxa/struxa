<?php

declare(strict_types=1);

namespace App\Config;

use App\Blueprint\BlueprintImportOptions;
use App\ImportExport\ImportExportService;
use App\Settings\SiteUrlResolver;

/**
 * CMI-lite: named packages, export wrapper, diff preview, staged import.
 */
final class ConfigPackageService
{
    public function __construct(
        private readonly ConfigStructureExporter $exporter,
        private readonly ImportExportService $importExport,
        private readonly ConfigDiffService $diff,
        private readonly ConfigExtendedImporter $extendedImporter,
        private readonly ConfigPackageStore $store,
    ) {
    }

    /**
     * @param list<string>|null $overrideScopes
     * @return array<string, mixed>
     */
    public function exportDocument(
        string $packageId,
        ?array $overrideScopes,
        bool $includeEntries,
        string $label,
        ?string $sourceEnvironment = null,
    ): array {
        $pkg = ConfigPackageRegistry::findBuiltIn($packageId);
        $scopes = $overrideScopes !== null && $overrideScopes !== []
            ? ConfigPackageRegistry::normalizeScopes($overrideScopes)
            : ($pkg !== null ? $pkg->scopes : ConfigPackageRegistry::ALL_SCOPES);
        if ($scopes === []) {
            $scopes = ['content_types', 'settings', 'menus', 'meta'];
        }
        $structure = $this->exporter->collect($scopes, $includeEntries);

        return $this->wrap($packageId, $label, $scopes, $structure, $sourceEnvironment);
    }

    /**
     * @param array<string, mixed> $structure
     * @param list<string> $scopes
     * @return array<string, mixed>
     */
    public function wrap(
        string $packageId,
        string $label,
        array $scopes,
        array $structure,
        ?string $sourceEnvironment = null,
    ): array {
        return [
            'cms_config_package_version' => ConfigPackageRegistry::PACKAGE_VERSION,
            'package_id' => $packageId,
            'label' => $label,
            'exported_at' => gmdate('c'),
            'source_environment' => $sourceEnvironment ?? '',
            'source_site_url' => SiteUrlResolver::resolve(),
            'scopes' => $scopes,
            'structure' => $structure,
        ];
    }

    /**
     * @param array<string, mixed> $document
     * @return array{
     *     scopes: list<string>,
     *     structure: array<string, mixed>,
     *     label: string,
     *     package_id: string
     * }
     */
    public function unwrap(array $document): array
    {
        if (isset($document['cms_config_package_version']) && isset($document['structure']) && is_array($document['structure'])) {
            $scopes = isset($document['scopes']) && is_array($document['scopes'])
                ? ConfigPackageRegistry::normalizeScopes($document['scopes'])
                : $this->inferScopes($document['structure']);

            return [
                'scopes' => $scopes,
                'structure' => $document['structure'],
                'label' => (string) ($document['label'] ?? 'Config package'),
                'package_id' => (string) ($document['package_id'] ?? 'custom'),
            ];
        }

        if (isset($document['cms_structure_export_version'])) {
            return [
                'scopes' => $this->inferScopes($document),
                'structure' => $document,
                'label' => 'Structure export',
                'package_id' => 'legacy',
            ];
        }

        throw new \InvalidArgumentException(
            'Unrecognized file: expected cms_config_package_version or cms_structure_export_version.'
        );
    }

    /**
     * @param array<string, mixed> $document
     * @return array{
     *     diff: array<string, mixed>,
     *     import: array{errors: list<string>, warnings: list<string>, applied: list<string>},
     *     scopes: list<string>,
     *     label: string
     * }
     */
    public function preview(array $document, ?array $overrideScopes, BlueprintImportOptions $opt): array
    {
        $unwrapped = $this->unwrap($document);
        $scopes = $overrideScopes !== null && $overrideScopes !== []
            ? ConfigPackageRegistry::normalizeScopes($overrideScopes)
            : $unwrapped['scopes'];
        $structure = $unwrapped['structure'];

        $local = $this->exporter->collect($scopes, false, 50);
        $diff = $this->diff->diff($local, $structure, $scopes);
        $import = $this->import($structure, $scopes, $opt);

        return [
            'diff' => $diff,
            'import' => $import,
            'scopes' => $scopes,
            'label' => $unwrapped['label'],
            'package_id' => $unwrapped['package_id'],
        ];
    }

    /**
     * @param array<string, mixed> $structure
     * @param list<string> $scopes
     * @return array{errors: list<string>, warnings: list<string>, applied: list<string>}
     */
    public function import(array $structure, array $scopes, BlueprintImportOptions $opt): array
    {
        $legacyScopes = array_values(array_intersect($scopes, ['meta', 'settings', 'menus', 'content_types', 'entries']));
        $result = ['errors' => [], 'warnings' => [], 'applied' => []];

        if ($legacyScopes !== []) {
            $version = (string) ($structure['cms_structure_export_version'] ?? '1.0');
            if ($version !== '1.0' && $version !== ConfigStructureExporter::STRUCTURE_VERSION) {
                return [
                    'errors' => ['Unsupported cms_structure_export_version: ' . $version],
                    'warnings' => [],
                    'applied' => [],
                ];
            }
            $legacyPayload = $structure;
            $legacyPayload['cms_structure_export_version'] = '1.0';
            $partial = $this->importExport->importJson($legacyPayload, $legacyScopes, $opt);
            $result['errors'] = array_merge($result['errors'], $partial['errors']);
            $result['warnings'] = array_merge($result['warnings'], $partial['warnings']);
            $result['applied'] = array_merge($result['applied'], $partial['applied']);
        }

        $extendedScopes = array_values(array_intersect($scopes, ['roles', 'mobile', 'commerce']));
        if ($extendedScopes !== [] && $result['errors'] === []) {
            $ext = $this->extendedImporter->apply($structure, $extendedScopes, $opt->dryRun, $opt->merge);
            $result['warnings'] = array_merge($result['warnings'], $ext['warnings']);
            $result['applied'] = array_merge($result['applied'], $ext['applied']);
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $structure
     * @return list<string>
     */
    private function inferScopes(array $structure): array
    {
        $scopes = [];
        if (isset($structure['content_types'])) {
            $scopes[] = 'content_types';
        }
        if (isset($structure['menus'])) {
            $scopes[] = 'menus';
        }
        if (isset($structure['settings'])) {
            $scopes[] = 'settings';
        }
        if (isset($structure['active_theme_slug']) || isset($structure['site_profile'])) {
            $scopes[] = 'meta';
        }
        if (isset($structure['content_entries'])) {
            $scopes[] = 'entries';
        }
        if (isset($structure['roles'])) {
            $scopes[] = 'roles';
        }
        if (isset($structure['mobile_settings'])) {
            $scopes[] = 'mobile';
        }
        if (isset($structure['commerce'])) {
            $scopes[] = 'commerce';
        }

        return ConfigPackageRegistry::normalizeScopes($scopes);
    }
}
