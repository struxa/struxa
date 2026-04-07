<?php

declare(strict_types=1);

namespace App\Plugin;

use PDO;

/**
 * Runs optional `uninstall.sql` at the plugin root when a plugin is removed from disk,
 * then clears {@see cms_plugin_migrations} rows so a reinstall can apply migrations again.
 */
final class PluginUninstaller
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly PluginMigrationRunner $migrationRunner,
    ) {
    }

    public function uninstall(string $pluginSlug, string $pluginRoot): void
    {
        $path = rtrim($pluginRoot, '/\\') . '/uninstall.sql';
        if (is_readable($path)) {
            $sql = file_get_contents($path);
            if ($sql !== false && trim($sql) !== '') {
                $this->migrationRunner->executeScript($sql);
            }
        }

        $stmt = $this->pdo->prepare('DELETE FROM cms_plugin_migrations WHERE plugin_slug = ?');
        $stmt->execute([$pluginSlug]);
    }
}
