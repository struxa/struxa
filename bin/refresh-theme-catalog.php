#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * CLI wrapper for struxa-admin CatalogPublisher::publishBundledStruxaThemeToCatalog().
 * Prefer Admin → Extensions → Struxa catalog → Settings → Publish bundled theme.
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

use App\Cli\CmsCliEnv;
use App\Plugin\PluginManager;
use App\Plugin\PluginScanner;
use StruxaAdmin\CatalogPublisher;
use StruxaAdmin\CatalogSettings;
use StruxaAdmin\CatalogSubmissionRepository;
use StruxaAdmin\GitHubRepoClient;

if (is_readable($root . '/.env')) {
    Dotenv\Dotenv::createImmutable($root)->safeLoad();
}

$discovered = (new PluginScanner($root))->findBySlug('struxa-admin');
if ($discovered === null) {
    fwrite(STDERR, "ERROR: struxa-admin plugin is not installed under plugins/struxa-admin/\n");
    fwrite(STDERR, "Use Admin → Extensions → Struxa catalog → Settings → Publish bundled theme.\n");
    exit(1);
}

PluginManager::registerPsr4Autoload($discovered);

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
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: Database connection failed — ' . $e->getMessage() . "\n");
    exit(1);
}

$settings = new CatalogSettings($pdo, $root);
$publisher = new CatalogPublisher($settings, new CatalogSubmissionRepository($pdo), new GitHubRepoClient($settings->githubToken()));
$result = $publisher->publishBundledStruxaThemeToCatalog();

if (!$result['ok']) {
    fwrite(STDERR, 'ERROR: ' . ($result['error'] ?? 'Publish failed.') . "\n");
    exit(1);
}

$ver = trim((string) ($result['version'] ?? ''));
$dist = (string) ($result['dist_root'] ?? $settings->distRoot());
echo "Published bundled Struxa Vision" . ($ver !== '' ? ' v' . $ver : '') . " to {$dist}/repo.json\n";
echo "Next: Admin → Themes → Reinstall from catalog" . ($ver !== '' ? ' (v' . $ver . ')' : '') . "\n";
