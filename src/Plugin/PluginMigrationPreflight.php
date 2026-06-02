<?php

declare(strict_types=1);

namespace App\Plugin;

use PDO;

/**
 * Summarises pending plugin SQL migrations before activation.
 */
final class PluginMigrationPreflight
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array{pending: list<string>, warnings: list<string>}
     */
    public function summarize(string $pluginSlug, string $migrationsDir): array
    {
        if (!is_dir($migrationsDir)) {
            return ['pending' => [], 'warnings' => []];
        }

        $applied = $this->appliedNames($pluginSlug);
        $files = glob(rtrim($migrationsDir, '/') . '/*.sql') ?: [];
        sort($files, SORT_STRING);
        $pending = [];
        $warnings = [];

        foreach ($files as $path) {
            $name = basename($path);
            if (isset($applied[$name])) {
                continue;
            }
            $pending[] = $name;
            $raw = file_get_contents($path);
            if ($raw === false) {
                $warnings[] = $name . ': could not read migration file.';

                continue;
            }
            $upper = strtoupper($raw);
            if (str_contains($upper, 'DROP TABLE') || str_contains($upper, 'DROP COLUMN')) {
                $warnings[] = $name . ': contains destructive DROP statements.';
            } elseif (str_contains($upper, 'ALTER TABLE')) {
                $warnings[] = $name . ': alters existing tables.';
            }
        }

        return ['pending' => $pending, 'warnings' => $warnings];
    }

    /**
     * @return array<string, true>
     */
    private function appliedNames(string $pluginSlug): array
    {
        try {
            $stmt = $this->pdo->prepare('SELECT name FROM cms_plugin_migrations WHERE plugin_slug = ?');
            $stmt->execute([$pluginSlug]);
        } catch (\PDOException) {
            return [];
        }
        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $name) {
            $map[(string) $name] = true;
        }
        $stmt->closeCursor();

        return $map;
    }
}
