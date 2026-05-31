<?php

declare(strict_types=1);

namespace App\Plugin;

use App\CmsVersion;
use App\Filter\FilterHook;
use PDO;

/**
 * Pre-activation compatibility checks: runtime, CMS version, dependencies, manifest contract, migrations.
 */
final class PluginCompatibilityChecker
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly PluginRepository $plugins,
        private readonly PluginMigrationPreflight $migrationPreflight,
    ) {
    }

    public function check(DiscoveredPlugin $plugin, ?PluginScanner $scanner = null, bool $includeMigrationPreflight = true): PluginCompatibilityReport
    {
        $errors = [];
        $warnings = [];
        $checks = [];
        $m = $plugin->manifest;

        $this->checkPhp($m, $errors, $checks);
        $this->checkCmsVersion($m, $errors, $warnings, $checks);
        $this->checkPhpExtensions($m, $errors, $checks);
        $this->checkManifestContract($m, $errors, $warnings, $checks);
        $this->checkMainClass($m, $errors, $checks);
        $this->checkNavOptions($m, $errors, $checks);
        $this->checkParentPlugin($m, $scanner, $errors, $checks);
        $this->checkRequiresPlugins($m, $scanner, $errors, $checks);
        $this->checkConflicts($m, $errors, $checks);

        if ($includeMigrationPreflight) {
            $this->checkMigrations($plugin, $errors, $warnings, $checks);
        }

        return new PluginCompatibilityReport($errors, $warnings, $checks);
    }

    /**
     * @param list<string> $errors
     * @param list<array{label: string, status: string, detail: string}> $checks
     */
    private function checkPhp(PluginManifest $m, array &$errors, array &$checks): void
    {
        if ($m->requiresPhp === null) {
            $checks[] = ['label' => 'PHP', 'status' => 'ok', 'detail' => PHP_VERSION];

            return;
        }
        if (!version_compare(PHP_VERSION, $m->requiresPhp, '>=')) {
            $msg = 'Requires PHP ' . $m->requiresPhp . ' or newer (running ' . PHP_VERSION . ').';
            $errors[] = $msg;
            $checks[] = ['label' => 'PHP', 'status' => 'error', 'detail' => $msg];

            return;
        }
        $checks[] = ['label' => 'PHP', 'status' => 'ok', 'detail' => PHP_VERSION . ' (requires ' . $m->requiresPhp . '+)'];
    }

    /**
     * @param list<string> $errors
     * @param list<string> $warnings
     * @param list<array{label: string, status: string, detail: string}> $checks
     */
    private function checkCmsVersion(PluginManifest $m, array &$errors, array &$warnings, array &$checks): void
    {
        $current = CmsVersion::CURRENT;
        if ($m->requiresCmsVersion !== null && !version_compare($current, $m->requiresCmsVersion, '>=')) {
            $msg = 'Requires Struxa ' . $m->requiresCmsVersion . ' or newer (installed ' . $current . ').';
            $errors[] = $msg;
            $checks[] = ['label' => 'Struxa CMS', 'status' => 'error', 'detail' => $msg];

            return;
        }
        if ($m->maxCmsVersion !== null && version_compare($current, $m->maxCmsVersion, '>')) {
            $msg = 'Not compatible with Struxa versions above ' . $m->maxCmsVersion . ' (installed ' . $current . ').';
            $errors[] = $msg;
            $checks[] = ['label' => 'Struxa CMS', 'status' => 'error', 'detail' => $msg];

            return;
        }
        $detail = 'Installed ' . $current;
        if ($m->requiresCmsVersion !== null) {
            $detail .= ' (requires ' . $m->requiresCmsVersion . '+)';
        }
        $checks[] = ['label' => 'Struxa CMS', 'status' => 'ok', 'detail' => $detail];

        if ($m->testedUpTo !== null && version_compare($current, $m->testedUpTo, '>')) {
            $msg = 'Author tested up to Struxa ' . $m->testedUpTo . ' only; you are on ' . $current . '.';
            $warnings[] = $msg;
            $checks[] = ['label' => 'Tested up to', 'status' => 'warn', 'detail' => $msg];
        }
    }

    /**
     * @param list<string> $errors
     * @param list<array{label: string, status: string, detail: string}> $checks
     */
    private function checkPhpExtensions(PluginManifest $m, array &$errors, array &$checks): void
    {
        if ($m->requiresExt === []) {
            return;
        }
        $missing = [];
        foreach ($m->requiresExt as $ext) {
            if (!extension_loaded($ext)) {
                $missing[] = $ext;
            }
        }
        if ($missing !== []) {
            $msg = 'Missing PHP extensions: ' . implode(', ', $missing) . '.';
            $errors[] = $msg;
            $checks[] = ['label' => 'PHP extensions', 'status' => 'error', 'detail' => $msg];

            return;
        }
        $checks[] = ['label' => 'PHP extensions', 'status' => 'ok', 'detail' => implode(', ', $m->requiresExt)];
    }

    /**
     * @param list<string> $errors
     * @param list<string> $warnings
     * @param list<array{label: string, status: string, detail: string}> $checks
     */
    private function checkManifestContract(PluginManifest $m, array &$errors, array &$warnings, array &$checks): void
    {
        foreach ($m->capabilities as $cap) {
            if (!PluginCapability::isValid($cap)) {
                $msg = 'Unknown capability "' . $cap . '" in plugin.json.';
                $errors[] = $msg;
            }
        }
        if ($m->capabilities !== []) {
            $checks[] = [
                'label' => 'Capabilities',
                'status' => 'ok',
                'detail' => implode(', ', $m->capabilities),
            ];
        }

        foreach ($m->hookFilters as $hook) {
            if (!FilterHook::isValid($hook)) {
                $msg = 'Unknown filter hook "' . $hook . '" in plugin.json hooks.filters.';
                $errors[] = $msg;
            }
        }
        foreach ($m->hookEvents as $event) {
            if (!PluginKnownEvents::isValid($event)) {
                $msg = 'Unknown event "' . $event . '" in plugin.json hooks.events.';
                $errors[] = $msg;
            }
        }
        if ($m->hookFilters !== [] || $m->hookEvents !== []) {
            $parts = [];
            if ($m->hookFilters !== []) {
                $parts[] = count($m->hookFilters) . ' filter(s)';
            }
            if ($m->hookEvents !== []) {
                $parts[] = count($m->hookEvents) . ' event(s)';
            }
            $checks[] = ['label' => 'Declared hooks', 'status' => 'ok', 'detail' => implode(', ', $parts)];
        }

        if ($m->databaseTables !== []) {
            $checks[] = [
                'label' => 'Database tables',
                'status' => 'ok',
                'detail' => implode(', ', $m->databaseTables),
            ];
        }
    }

    /**
     * @param list<string> $errors
     * @param list<array{label: string, status: string, detail: string}> $checks
     */
    private function checkMainClass(PluginManifest $m, array &$errors, array &$checks): void
    {
        if ($m->mainClass === null) {
            return;
        }
        if (!class_exists($m->mainClass)) {
            $msg = 'Main class "' . $m->mainClass . '" could not be loaded. Check autoload.psr4 in plugin.json.';
            $errors[] = $msg;
            $checks[] = ['label' => 'Main class', 'status' => 'error', 'detail' => $msg];

            return;
        }
        $ref = new \ReflectionClass($m->mainClass);
        if (!$ref->implementsInterface(PluginServiceProviderInterface::class)) {
            $msg = 'Main class must implement ' . PluginServiceProviderInterface::class . '.';
            $errors[] = $msg;
            $checks[] = ['label' => 'Main class', 'status' => 'error', 'detail' => $msg];

            return;
        }
        $checks[] = ['label' => 'Main class', 'status' => 'ok', 'detail' => $m->mainClass];
    }

    /**
     * @param list<string> $errors
     * @param list<array{label: string, status: string, detail: string}> $checks
     */
    private function checkNavOptions(PluginManifest $m, array &$errors, array &$checks): void
    {
        if ($m->nestedAdminNav && $m->parentPluginSlug !== null && $m->parentPluginSlug !== '') {
            $errors[] = 'plugin.json: use either nested_admin_nav or parent_plugin, not both.';
        }
    }

    /**
     * @param list<string> $errors
     * @param list<array{label: string, status: string, detail: string}> $checks
     */
    private function checkParentPlugin(PluginManifest $m, ?PluginScanner $scanner, array &$errors, array &$checks): void
    {
        $parent = $m->parentPluginSlug;
        if ($parent === null || $parent === '') {
            return;
        }
        if (strcasecmp($parent, $m->slug) === 0) {
            $errors[] = 'plugin.json parent_plugin cannot be the same as this plugin slug.';

            return;
        }
        if ($scanner !== null && $scanner->findBySlug($parent) === null) {
            $msg = 'parent_plugin "' . $parent . '" is not installed on disk.';
            $errors[] = $msg;
            $checks[] = ['label' => 'Parent plugin', 'status' => 'error', 'detail' => $msg];

            return;
        }
        $checks[] = ['label' => 'Parent plugin', 'status' => 'ok', 'detail' => $parent];
    }

    /**
     * @param list<string> $errors
     * @param list<array{label: string, status: string, detail: string}> $checks
     */
    private function checkRequiresPlugins(PluginManifest $m, ?PluginScanner $scanner, array &$errors, array &$checks): void
    {
        if ($m->requiresPlugins === []) {
            return;
        }
        if ($scanner === null) {
            return;
        }
        foreach ($m->requiresPlugins as $depSlug => $constraint) {
            $dep = $scanner->findBySlug($depSlug);
            if ($dep === null) {
                $msg = 'Requires plugin "' . $depSlug . '" but it is not installed.';
                $errors[] = $msg;
                $checks[] = ['label' => 'Dependency: ' . $depSlug, 'status' => 'error', 'detail' => $msg];

                continue;
            }
            $record = $this->plugins->findBySlug($depSlug);
            if ($record === null || !$record->isActive) {
                $msg = 'Requires plugin "' . $depSlug . '" to be active first.';
                $errors[] = $msg;
                $checks[] = ['label' => 'Dependency: ' . $depSlug, 'status' => 'error', 'detail' => $msg];

                continue;
            }
            $depVersion = $dep->manifest->version;
            if (!PluginSemverConstraint::satisfies($depVersion, $constraint)) {
                $msg = 'Requires ' . $depSlug . ' version ' . $constraint . ' (installed ' . $depVersion . ').';
                $errors[] = $msg;
                $checks[] = ['label' => 'Dependency: ' . $depSlug, 'status' => 'error', 'detail' => $msg];

                continue;
            }
            $checks[] = [
                'label' => 'Dependency: ' . $depSlug,
                'status' => 'ok',
                'detail' => $depVersion . ' satisfies ' . $constraint,
            ];
        }
    }

    /**
     * @param list<string> $errors
     * @param list<array{label: string, status: string, detail: string}> $checks
     */
    private function checkConflicts(PluginManifest $m, array &$errors, array &$checks): void
    {
        if ($m->conflicts === []) {
            return;
        }
        foreach ($m->conflicts as $conflictSlug) {
            $record = $this->plugins->findBySlug($conflictSlug);
            if ($record !== null && $record->isActive) {
                $msg = 'Conflicts with active plugin "' . $conflictSlug . '". Deactivate it first.';
                $errors[] = $msg;
                $checks[] = ['label' => 'Conflict', 'status' => 'error', 'detail' => $msg];
            }
        }
        if ($errors === []) {
            $checks[] = ['label' => 'Conflicts', 'status' => 'ok', 'detail' => 'No conflicting plugins active.'];
        }
    }

    /**
     * @param list<string> $errors
     * @param list<string> $warnings
     * @param list<array{label: string, status: string, detail: string}> $checks
     */
    private function checkMigrations(DiscoveredPlugin $plugin, array &$errors, array &$warnings, array &$checks): void
    {
        $m = $plugin->manifest;
        $dir = rtrim($plugin->rootPath, '/') . '/' . $m->databaseMigrationsPath;
        if (!is_dir($dir)) {
            if ($m->databaseTables !== []) {
                $checks[] = [
                    'label' => 'Migrations',
                    'status' => 'warn',
                    'detail' => 'Declares tables but no migrations folder at ' . $m->databaseMigrationsPath . '/.',
                ];
            }

            return;
        }

        $summary = $this->migrationPreflight->summarize($m->slug, $dir);
        if ($summary['pending'] === []) {
            $checks[] = ['label' => 'Migrations', 'status' => 'ok', 'detail' => 'All migrations already applied.'];

            return;
        }
        $checks[] = [
            'label' => 'Migrations',
            'status' => 'ok',
            'detail' => count($summary['pending']) . ' pending: ' . implode(', ', $summary['pending']),
        ];
        foreach ($summary['warnings'] as $w) {
            $warnings[] = 'Migration ' . $w;
            $checks[] = ['label' => 'Migration review', 'status' => 'warn', 'detail' => $w];
        }
    }
}
