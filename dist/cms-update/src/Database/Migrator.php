<?php

declare(strict_types=1);

namespace App\Database;

use PDO;

final class Migrator
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $migrationsDir
    ) {
    }

    /**
     * @return list<string> Applied migration basenames (this run)
     */
    public function run(): array
    {
        $this->ensureMigrationsTable();

        $files = glob($this->migrationsDir . '/*.sql') ?: [];
        sort($files, SORT_STRING);

        $applied = $this->appliedNames();
        $batch = $this->nextBatch();
        $new = [];

        foreach ($files as $path) {
            $name = basename($path);
            if (isset($applied[$name])) {
                continue;
            }

            $sql = file_get_contents($path);
            if ($sql === false) {
                throw new \RuntimeException("Cannot read migration: {$name}");
            }

            $this->runSqlFile($sql);

            $stmt = $this->pdo->prepare('INSERT INTO cms_migrations (name, batch) VALUES (:name, :batch)');
            $stmt->execute(['name' => $name, 'batch' => $batch]);
            $new[] = $name;
        }

        return $new;
    }

    private function ensureMigrationsTable(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS cms_migrations (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL UNIQUE,
                batch INT UNSIGNED NOT NULL DEFAULT 1,
                executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    /**
     * @return array<string, true>
     */
    private function appliedNames(): array
    {
        $rows = $this->pdo->query('SELECT name FROM cms_migrations')->fetchAll(PDO::FETCH_COLUMN);
        $map = [];
        foreach ($rows as $name) {
            $map[(string) $name] = true;
        }

        return $map;
    }

    private function nextBatch(): int
    {
        $v = $this->pdo->query('SELECT COALESCE(MAX(batch), 0) + 1 FROM cms_migrations')->fetchColumn();

        return (int) $v;
    }

    private function runSqlFile(string $sql): void
    {
        $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
        $parts = preg_split('/;\s*(?=\R)/', $sql) ?: [];

        // No wrapping transaction: MySQL commits DDL per statement; mixing DDL in a transaction errors.
        foreach ($parts as $part) {
            $stmt = trim($part);
            if ($stmt === '') {
                continue;
            }
            $this->pdo->exec($stmt);
        }
    }
}
