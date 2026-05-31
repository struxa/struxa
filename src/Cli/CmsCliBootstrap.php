<?php

declare(strict_types=1);

namespace App\Cli;

use App\Jobs\JobHandlerContext;
use App\Jobs\JobRepository;
use App\Jobs\JobWorker;
use App\Jobs\Jobs;
use App\Settings;
use PDO;
use PDOException;

/**
 * Shared PDO + app boot for bin/cms.php subcommands.
 */
final class CmsCliBootstrap
{
    public static function connectDatabase(string $root): PDO
    {
        if (is_readable($root . '/.env')) {
            \Dotenv\Dotenv::createImmutable($root)->safeLoad();
        }

        $dbHost = CmsCliEnv::get('DB_HOST', '127.0.0.1');
        $dbPort = CmsCliEnv::get('DB_PORT', '3306');
        $dbName = CmsCliEnv::get('DB_NAME', 'studio');
        $dbUser = CmsCliEnv::get('DB_USER', 'studio');
        $dbPass = CmsCliEnv::get('DB_PASS', 'studio');
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $dbHost, $dbPort, $dbName);

        try {
            return new PDO($dsn, $dbUser, $dbPass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        } catch (PDOException $e) {
            fwrite(STDERR, 'Database connection failed: ' . $e->getMessage() . "\n");
            exit(1);
        }
    }

    public static function bootApp(PDO $pdo, string $root): void
    {
        Settings::boot($pdo);
        Jobs::boot($pdo, $root);
    }

    public static function makeWorker(PDO $pdo, string $root): JobWorker
    {
        $repository = new JobRepository($pdo);
        $handlers = Jobs::handlers();
        $context = new JobHandlerContext($pdo, $root, Jobs::queue());

        return new JobWorker($repository, $handlers, $context);
    }
}
