<?php

declare(strict_types=1);

namespace App\Plugin;

use App\Database\SqlMigrationStatement;
use PDO;
use PDOException;

/**
 * Runs *.sql from a plugin's migrations/ folder; tracked in cms_plugin_migrations.
 */
final class PluginMigrationRunner
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return list<string> newly applied basenames
     */
    public function runPending(string $pluginSlug, string $migrationsDir): array
    {
        if (!is_dir($migrationsDir)) {
            return [];
        }

        $applied = $this->appliedNames($pluginSlug);
        $files = glob($migrationsDir . '/*.sql') ?: [];
        sort($files, SORT_STRING);
        $new = [];

        foreach ($files as $path) {
            $name = basename($path);
            if (isset($applied[$name])) {
                continue;
            }
            $sql = file_get_contents($path);
            if ($sql === false) {
                throw new \RuntimeException("Cannot read plugin migration: {$pluginSlug}/{$name}");
            }
            $this->runSqlFile($sql);
            $stmt = $this->pdo->prepare('INSERT INTO cms_plugin_migrations (plugin_slug, name) VALUES (?, ?)');
            $stmt->execute([$pluginSlug, $name]);
            $new[] = $name;
        }

        return $new;
    }

    /**
     * @return array<string, true>
     */
    private function appliedNames(string $pluginSlug): array
    {
        $stmt = $this->pdo->prepare('SELECT name FROM cms_plugin_migrations WHERE plugin_slug = ?');
        $stmt->execute([$pluginSlug]);
        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $name) {
            $map[(string) $name] = true;
        }
        $stmt->closeCursor();

        return $map;
    }

    /**
     * Executes one or more semicolon-separated SQL statements (e.g. plugin uninstall.sql).
     */
    public function executeScript(string $sql): void
    {
        $this->runSqlFile($sql);
    }

    private function runSqlFile(string $sql): void
    {
        $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
        $parts = preg_split('/;\s*(?=\R)/', $sql) ?: [];
        foreach ($parts as $part) {
            $stmtSql = trim($part);
            if ($stmtSql === '') {
                continue;
            }
            $this->executeStatement($stmtSql);
        }
    }

    private function executeStatement(string $stmtSql): void
    {
        try {
            $result = $this->pdo->query($stmtSql);
            if ($result instanceof \PDOStatement) {
                $this->drainStatement($result);
            }
        } catch (PDOException $e) {
            if (SqlMigrationStatement::isBenignDuplicateSchemaError($e)) {
                error_log('[plugin-migration] Skipped already-applied DDL: ' . $e->getMessage());

                return;
            }
            throw $e;
        }
    }

    /**
     * Idempotent migrations may EXECUTE dynamic SQL that returns rows (e.g. SELECT no-op).
     * Consume all result sets before the next statement (MySQL PDO unbuffered-query guard).
     */
    private function drainStatement(\PDOStatement $result): void
    {
        try {
            do {
                $result->fetchAll();
            } while ($result->nextRowset());
        } catch (\PDOException) {
            // Some drivers throw when there is no next rowset.
        } finally {
            $result->closeCursor();
        }
    }
}
