#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Database\Migrator;

$root = dirname(__DIR__);

require $root . '/vendor/autoload.php';

if (is_readable($root . '/.env')) {
    Dotenv\Dotenv::createImmutable($root)->safeLoad();
}

$dbHost = $_ENV['DB_HOST'] ?? '127.0.0.1';
$dbPort = $_ENV['DB_PORT'] ?? '3306';
$dbName = $_ENV['DB_NAME'] ?? 'studio';
$dbUser = $_ENV['DB_USER'] ?? 'studio';
$dbPass = $_ENV['DB_PASS'] ?? 'studio';

$dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $dbHost, $dbPort, $dbName);

try {
    $pdo = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    fwrite(STDERR, "Database connection failed: {$e->getMessage()}\n");
    exit(1);
}

$bootstrapSql = $root . '/database/install/phpauth_bootstrap.sql';
if (is_readable($bootstrapSql)) {
    $check = $pdo->query("SHOW TABLES LIKE 'phpauth_users'");
    if ($check === false || $check->rowCount() === 0) {
        fwrite(STDOUT, "Bootstrapping PHPAuth tables (database/install/phpauth_bootstrap.sql)...\n");
        try {
            migrate_run_sql_file($pdo, $bootstrapSql);
        } catch (Throwable $e) {
            fwrite(STDERR, "PHPAuth bootstrap failed: {$e->getMessage()}\n");
            exit(1);
        }
    }
}

$dir = $root . '/database/migrations';

try {
    $migrator = new Migrator($pdo, $dir);
    $applied = $migrator->run();
} catch (Throwable $e) {
    fwrite(STDERR, "Migration failed: {$e->getMessage()}\n");
    exit(1);
}

if ($applied === []) {
    echo "Migrations: already up to date.\n";
} else {
    echo "Migrations applied:\n";
    foreach ($applied as $name) {
        echo "  - {$name}\n";
    }
}

exit(0);

/**
 * Same statement splitting as {@see Migrator} / web installer (no wrapping transaction).
 */
function migrate_run_sql_file(PDO $pdo, string $path): void
{
    $sql = file_get_contents($path);
    if ($sql === false) {
        throw new RuntimeException("Cannot read: {$path}");
    }
    $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
    $parts = preg_split('/;\s*(?=\R)/', $sql) ?: [];
    foreach ($parts as $part) {
        $stmt = trim($part);
        if ($stmt === '') {
            continue;
        }
        $pdo->exec($stmt);
    }
}
