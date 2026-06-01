<?php

declare(strict_types=1);

/**
 * One-shot check for struxa-admin catalog admin route registration (cPanel-safe).
 * Run: php scripts/verify-catalog-admin-routes.php
 */

$root = dirname(__DIR__);
chdir($root);

require $root . '/vendor/autoload.php';

if (is_readable($root . '/.env')) {
    Dotenv\Dotenv::createImmutable($root)->safeLoad();
}

$registrarFile = $root . '/src/Plugin/StruxaCatalogAdminRouteRegistrar.php';
$registrarSrc = is_file($registrarFile) ? (string) file_get_contents($registrarFile) : '';

echo "=== Catalog admin route verify ===\n\n";
echo 'registerPsr4Autoload in registrar: '
    . (str_contains($registrarSrc, 'PluginManager::registerPsr4Autoload($discovered)') ? "yes\n" : "NO — curl registrar from GitHub main\n");
echo 'static registerAutoloadForPlugin in registrar: '
    . (preg_match('/PluginManager::registerAutoloadForPlugin\s*\(/', $registrarSrc) === 1 ? "BAD — old file\n" : "no\n");

$dbHost = $_ENV['DB_HOST'] ?? '127.0.0.1';
$dbPort = $_ENV['DB_PORT'] ?? '3306';
$dbName = $_ENV['DB_NAME'] ?? '';
$dbUser = $_ENV['DB_USER'] ?? '';
$dbPass = $_ENV['DB_PASS'] ?? '';
$dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $dbHost, $dbPort, $dbName);

try {
    $pdo = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    fwrite(STDERR, 'DB: ' . $e->getMessage() . "\n");
    exit(1);
}

$row = $pdo->query("SELECT slug, is_active FROM cms_plugins WHERE slug = 'struxa-admin'")->fetch();
echo 'cms_plugins row: ' . ($row === false ? "NONE\n" : json_encode($row) . "\n");

$_SERVER['REQUEST_URI'] = '/admin';
$_SERVER['REQUEST_METHOD'] = 'GET';

try {
    /** @var \Slim\App $app */
    $app = require $root . '/bootstrap/web_app.php';
} catch (Throwable $e) {
    echo 'BOOTSTRAP FAILED: ' . $e->getMessage() . "\n";
    exit(1);
}

if (class_exists(\App\Plugin\StruxaCatalogAdminRouteRegistrar::class)) {
    $themeManager = new \App\Theme\ThemeManager($root);
    $paths = \App\Theme\ThemeViewResolver::twigLoaderPaths($themeManager, $root . '/templates');
    $twigProbe = \Slim\Views\Twig::create($paths, ['cache' => false]);
    $skip = \App\Plugin\StruxaCatalogAdminRouteRegistrar::skipReason(
        $app,
        $twigProbe,
        new \App\Plugin\PluginRepository($pdo),
        new \App\Plugin\PluginScanner($root),
    );
    echo 'skipReason: ' . ($skip ?? 'none') . "\n";
}

$admin = [];
foreach ($app->getRouteCollector()->getRoutes() as $route) {
    $name = $route->getName();
    if (is_string($name) && str_starts_with($name, 'admin.struxa_catalog')) {
        $admin[] = $name;
    }
}

if ($admin === []) {
    echo "\nNO admin.struxa_catalog.* routes.\n";
    echo "Check error_log for: Catalog admin routes skipped / Core catalog admin routes failed\n";
    echo "If skipReason is none, run: grep catalog error_log | tail -5\n";
    exit(1);
}

echo "\nRegistered:\n";
foreach ($admin as $name) {
    echo "  $name\n";
}
echo "\nOK\n";
