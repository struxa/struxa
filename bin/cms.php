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
        echo "Commands: about | migrate | schedule:run | jobs:dispatch | jobs:work | jobs:status | maintenance:purge | check | cache:clear | help\n";
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
        if (\App\Settings::get('maintenance_auto_purge', '0') === '1') {
            $maint = new \App\Maintenance\MaintenanceService($pdo, $root);
            $purged = $maint->runScheduledPurges();
            $purgeTotal = array_sum($purged);
            if ($purgeTotal > 0) {
                fwrite(STDOUT, "Maintenance purges: {$purgeTotal} row(s) removed.\n");
            }
        } else {
            (new \App\Preview\PreviewTokenRepository($pdo))->deleteExpired();
        }
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
        \App\Publishing\ScheduleRunTracker::record($pdo);
        exit($report['errors'] === [] ? 0 : 1);

    case 'jobs:dispatch':
        require $root . '/vendor/autoload.php';
        $pdo = \App\Cli\CmsCliBootstrap::connectDatabase($root);
        \App\Cli\CmsCliBootstrap::bootApp($pdo, $root);
        $queue = \App\Jobs\Jobs::queue();
        $ids = [];
        if (\App\Settings::get('maintenance_auto_purge', '0') === '1') {
            $ids[] = $queue->enqueueScheduledPurges();
        }
        $ids[] = $queue->enqueuePublishDue();
        fwrite(STDOUT, 'Enqueued job id(s): ' . implode(', ', array_unique($ids)) . "\n");
        exit(0);

    case 'jobs:work':
        require $root . '/vendor/autoload.php';
        $pdo = \App\Cli\CmsCliBootstrap::connectDatabase($root);
        \App\Cli\CmsCliBootstrap::bootApp($pdo, $root);
        $limit = 10;
        $queueName = 'default';
        $sleepSeconds = 0;
        $once = false;
        foreach (array_slice($argv, 1) as $arg) {
            if ($arg === '--once') {
                $once = true;
            } elseif (str_starts_with($arg, '--limit=')) {
                $limit = max(1, (int) substr($arg, 8));
            } elseif (str_starts_with($arg, '--queue=')) {
                $queueName = trim(substr($arg, 8)) ?: 'default';
            } elseif (str_starts_with($arg, '--sleep=')) {
                $sleepSeconds = max(0, (int) substr($arg, 8));
            }
        }
        $worker = \App\Cli\CmsCliBootstrap::makeWorker($pdo, $root);
        $workerId = 'cli-' . getmypid();
        do {
            $report = $worker->run($queueName, $limit, $workerId);
            if ($report['released_stale'] > 0) {
                fwrite(STDOUT, "Recovered {$report['released_stale']} stale running job(s).\n");
            }
            foreach ($report['messages'] as $line) {
                $stream = str_contains($line, 'failed') || str_contains($line, 'will retry') ? STDERR : STDOUT;
                fwrite($stream, $line . "\n");
            }
            if ($report['processed'] === 0) {
                if (!$once && $sleepSeconds > 0) {
                    sleep($sleepSeconds);
                    continue;
                }
                if ($report['messages'] === []) {
                    fwrite(STDOUT, "No pending jobs.\n");
                }
                break;
            }
            if ($once) {
                break;
            }
        } while (!$once);

        exit($report['failed'] > 0 ? 1 : 0);

    case 'jobs:status':
        require $root . '/vendor/autoload.php';
        $pdo = \App\Cli\CmsCliBootstrap::connectDatabase($root);
        \App\Settings::boot($pdo);
        $repo = new \App\Jobs\JobRepository($pdo);
        if (!$repo->tableExists()) {
            fwrite(STDERR, "cms_jobs table missing — run php bin/cms.php migrate.\n");
            exit(1);
        }
        $counts = $repo->counts();
        $last = \App\Jobs\JobRunTracker::lastRunAt();
        fwrite(STDOUT, sprintf(
            "Queue: pending=%d running=%d failed=%d completed(24h)=%d\n",
            $counts['pending'],
            $counts['running'],
            $counts['failed'],
            $counts['completed_24h'],
        ));
        fwrite(STDOUT, 'Last worker run (UTC): ' . ($last ?? 'never') . "\n");
        exit(0);

    case 'maintenance:purge':
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
        $maint = new \App\Maintenance\MaintenanceService($pdo, $root);
        $result = $maint->runScheduledPurges();
        foreach ($result as $key => $count) {
            if ($count > 0) {
                fwrite(STDOUT, "{$key}: {$count}\n");
            }
        }
        $total = array_sum($result);
        fwrite(STDOUT, $total > 0 ? "Total removed: {$total}\n" : "Nothing to purge.\n");
        exit(0);

    case 'help':
    default:
        echo "Usage: php bin/cms.php <command>\n\n";
        echo "  about   Project summary\n";
        echo "  check   Load .env if present and test DB connection\n";
        echo "  migrate Run database/migrations (same as bin/migrate.php)\n";
        echo "  schedule:run Apply due scheduled publish/unpublish (also clears expired preview tokens)\n";
        echo "  jobs:dispatch Enqueue scheduled publish + retention purges for the background worker\n";
        echo "  jobs:work     Process pending background jobs (--once, --limit=N, --queue=name, --sleep=N)\n";
        echo "  jobs:status   Show queue counts and last worker heartbeat\n";
        echo "  maintenance:purge Run retention purges (preview tokens, external links, AI chat)\n";
        echo "  cache:clear Clear storefront file caches (public + internal)\n";
        echo "  help    This message\n";
        exit($cmd === 'help' ? 0 : 1);
}
