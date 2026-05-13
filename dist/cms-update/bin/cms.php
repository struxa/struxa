#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Cli\CmsCliEnv;

/**
 * Project CLI entry (migrate wrapper, quick environment checks).
 */

$root = dirname(__DIR__);
$argv = $_SERVER['argv'] ?? [];
array_shift($argv);
$cmd = $argv[0] ?? 'help';

switch ($cmd) {
    case 'migrate':
        require $root . '/bin/migrate.php';
        exit(0);

    case 'about':
        require $root . '/vendor/autoload.php';
        echo 'Struxa CMS ' . \App\CmsVersion::CURRENT . " — PHP 8.2+ / Slim / Twig / MySQL.\n";
        echo "Project root: {$root}\n";
        echo "Commands: about | migrate | schedule:run | check | cache:clear | help\n";
        exit(0);

    case 'cache:clear':
        require $root . '/bin/cache-clear.php';
        exit(0);

    case 'check':
        require $root . '/vendor/autoload.php';
        $envPath = $root . '/.env';
        if (!is_readable($envPath)) {
            echo "Note: no .env file (defaults may still work). Copy .env.example if you want explicit config.\n";
        } else {
            Dotenv\Dotenv::createImmutable($root)->safeLoad();
            echo ".env loaded.\n";
        }
        $dbHost = CmsCliEnv::get('DB_HOST', '127.0.0.1');
        $dbPort = CmsCliEnv::get('DB_PORT', '3306');
        $dbName = CmsCliEnv::get('DB_NAME', 'studio');
        $dbUser = CmsCliEnv::get('DB_USER', 'studio');
        $dbPass = CmsCliEnv::get('DB_PASS', 'studio');
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $dbHost, $dbPort, $dbName);
        try {
            $pdo = new PDO($dsn, $dbUser, $dbPass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $pdo->query('SELECT 1');
            echo "Database: OK (connected to {$dbName} @ {$dbHost}).\n";
        } catch (Throwable $e) {
            fwrite(STDERR, 'Database: FAILED — ' . $e->getMessage() . "\n");
            exit(1);
        }
        exit(0);

    case 'schedule:run':
        require $root . '/vendor/autoload.php';
        if (is_readable($root . '/.env')) {
            Dotenv\Dotenv::createImmutable($root)->safeLoad();
        }
        $dbHost = CmsCliEnv::get('DB_HOST', '127.0.0.1');
        $dbPort = CmsCliEnv::get('DB_PORT', '3306');
        $dbName = CmsCliEnv::get('DB_NAME', 'studio');
        $dbUser = CmsCliEnv::get('DB_USER', 'studio');
        $dbPass = CmsCliEnv::get('DB_PASS', 'studio');
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $dbHost, $dbPort, $dbName);
        try {
            $pdo = new PDO($dsn, $dbUser, $dbPass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        } catch (PDOException $e) {
            fwrite(STDERR, 'Database connection failed: ' . $e->getMessage() . "\n");
            exit(1);
        }
        \App\Settings::boot($pdo);
        (new \App\Preview\PreviewTokenRepository($pdo))->deleteExpired();
        $report = (new \App\Publishing\PublishScheduleService($pdo))->runDue();
        fwrite(STDOUT, sprintf(
            "Entries: published %d, unpublished %d. Pages: published %d, unpublished %d.\n",
            $report['published_entries'],
            $report['unpublished_entries'],
            $report['published_pages'],
            $report['unpublished_pages']
        ));
        foreach ($report['errors'] as $err) {
            fwrite(STDERR, $err . "\n");
        }
        exit($report['errors'] === [] ? 0 : 1);

    case 'help':
    default:
        echo "Usage: php bin/cms.php <command>\n\n";
        echo "  about   Project summary\n";
        echo "  check   Load .env if present and test DB connection\n";
        echo "  migrate Run database/migrations (same as bin/migrate.php)\n";
        echo "  schedule:run Apply due scheduled publish/unpublish (also clears expired preview tokens)\n";
        echo "  cache:clear Clear storefront file caches (public + internal)\n";
        echo "  help    This message\n";
        exit($cmd === 'help' ? 0 : 1);
}
