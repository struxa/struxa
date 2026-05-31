<?php

declare(strict_types=1);

namespace App\Plugin;

use PDO;

final class PluginValidator
{
    private ?PluginCompatibilityChecker $checker = null;

    public function __construct(?PDO $pdo = null)
    {
        if ($pdo !== null) {
            $repo = new PluginRepository($pdo);
            $this->checker = new PluginCompatibilityChecker($pdo, $repo, new PluginMigrationPreflight($pdo));
        }
    }

    public function compatibilityReport(DiscoveredPlugin $plugin, ?PluginScanner $scanner = null, bool $includeMigrationPreflight = true): PluginCompatibilityReport
    {
        if ($this->checker !== null) {
            return $this->checker->check($plugin, $scanner, $includeMigrationPreflight);
        }

        return new PluginCompatibilityReport($this->legacyActivationErrors($plugin, $scanner));
    }

    /**
     * @return list<string> error messages; empty = ok
     */
    public function activationErrors(DiscoveredPlugin $plugin, ?PluginScanner $scanner = null): array
    {
        return $this->compatibilityReport($plugin, $scanner)->activationErrors();
    }

    /**
     * @return list<string>
     */
    private function legacyActivationErrors(DiscoveredPlugin $plugin, ?PluginScanner $scanner): array
    {
        $errors = [];
        $m = $plugin->manifest;

        if ($m->requiresPhp !== null && !version_compare(PHP_VERSION, $m->requiresPhp, '>=')) {
            $errors[] = 'This plugin requires PHP ' . $m->requiresPhp . ' or newer (running ' . PHP_VERSION . ').';
        }

        if ($m->requiresCmsVersion !== null && !version_compare(\App\CmsVersion::CURRENT, $m->requiresCmsVersion, '>=')) {
            $errors[] = 'This plugin requires CMS version ' . $m->requiresCmsVersion . ' or newer.';
        }

        if ($m->nestedAdminNav && $m->parentPluginSlug !== null && $m->parentPluginSlug !== '') {
            $errors[] = 'plugin.json: use either nested_admin_nav or parent_plugin, not both.';
        }

        $parent = $m->parentPluginSlug;
        if ($parent !== null && $parent !== '') {
            if (strcasecmp($parent, $m->slug) === 0) {
                $errors[] = 'plugin.json parent_plugin cannot be the same as this plugin slug.';
            } elseif ($scanner !== null && $scanner->findBySlug($parent) === null) {
                $errors[] = 'plugin.json parent_plugin "' . $parent . '" is not installed on disk.';
            }
        }

        if ($m->mainClass !== null && !class_exists($m->mainClass)) {
            $errors[] = 'Main class "' . $m->mainClass . '" could not be loaded.';
        }

        if ($m->mainClass !== null && class_exists($m->mainClass)) {
            $ref = new \ReflectionClass($m->mainClass);
            if (!$ref->implementsInterface(PluginServiceProviderInterface::class)) {
                $errors[] = 'Main class must implement ' . PluginServiceProviderInterface::class . '.';
            }
        }

        return $errors;
    }
}
