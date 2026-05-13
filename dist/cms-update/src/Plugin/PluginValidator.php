<?php

declare(strict_types=1);

namespace App\Plugin;

use App\CmsVersion;

final class PluginValidator
{
    /**
     * @return list<string> error messages; empty = ok
     */
    public function activationErrors(DiscoveredPlugin $plugin, ?PluginScanner $scanner = null): array
    {
        $errors = [];
        $m = $plugin->manifest;

        if ($m->requiresPhp !== null && !version_compare(PHP_VERSION, $m->requiresPhp, '>=')) {
            $errors[] = 'This plugin requires PHP ' . $m->requiresPhp . ' or newer (running ' . PHP_VERSION . ').';
        }

        if ($m->requiresCmsVersion !== null && !version_compare(CmsVersion::CURRENT, $m->requiresCmsVersion, '>=')) {
            $errors[] = 'This plugin requires CMS version ' . $m->requiresCmsVersion . ' or newer.';
        }

        if ($m->nestedAdminNav && $m->parentPluginSlug !== null && $m->parentPluginSlug !== '') {
            $errors[] = 'plugin.json: use either nested_admin_nav (group this plugin\'s links under its own name) or parent_plugin (attach under another plugin), not both.';
        }

        $parent = $m->parentPluginSlug;
        if ($parent !== null && $parent !== '') {
            if (strcasecmp($parent, $m->slug) === 0) {
                $errors[] = 'plugin.json parent_plugin cannot be the same as this plugin slug.';
            } elseif ($scanner !== null && $scanner->findBySlug($parent) === null) {
                $errors[] = 'plugin.json parent_plugin "' . $parent . '" is not installed on disk (expected /plugins/' . $parent . '/).';
            }
        }

        if ($m->mainClass !== null && !class_exists($m->mainClass)) {
            $errors[] = 'Main class "' . $m->mainClass . '" could not be loaded. Check autoload.psr4 in plugin.json.';
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
