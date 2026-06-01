<?php

declare(strict_types=1);

/**
 * Server diagnostic: why admin.struxa_catalog.* routes are missing.
 * Run: php scripts/diagnose-struxa-catalog.php
 */

$root = dirname(__DIR__);
chdir($root);

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
    fwrite(STDERR, "DB connect failed: " . $e->getMessage() . "\n");
    exit(1);
}

echo "=== Struxa catalog route diagnostic ===\n\n";

$bootPath = $root . '/bootstrap/web_app.php';
$bootSrc = is_file($bootPath) ? (string) file_get_contents($bootPath) : '';
echo "bootstrap calls registerStruxaCatalogAdminRoutesIfNeeded: "
    . (str_contains($bootSrc, 'registerStruxaCatalogAdminRoutesIfNeeded') ? "yes\n" : "NO — update CMS (1.1.56+)\n");
echo "PluginManager::registerStruxaCatalogAdminRoutesIfNeeded: "
    . (method_exists(\App\Plugin\PluginManager::class, 'registerStruxaCatalogAdminRoutesIfNeeded') ? "yes\n" : "NO — old src/Plugin/PluginManager.php\n");
echo "StruxaCatalogAdminRouteRegistrar class: "
    . (class_exists(\App\Plugin\StruxaCatalogAdminRouteRegistrar::class) ? "yes\n" : "MISSING\n");

$row = $pdo->query("SELECT slug, is_active, version FROM cms_plugins WHERE slug = 'struxa-admin'")->fetch();
echo "cms_plugins row: ";
echo $row === false ? "NONE (install plugin + Repair in admin)\n" : json_encode($row) . "\n";

$pluginDir = $root . '/plugins/struxa-admin';
echo "plugins/struxa-admin: " . (is_dir($pluginDir) ? "yes\n" : "MISSING — install struxa-admin.zip\n");
echo "plugin.json: " . (is_file($pluginDir . '/plugin.json') ? "yes\n" : "MISSING\n");
echo "plugins/struxa-admin/src: " . (is_dir($pluginDir . '/src') ? "yes\n" : "MISSING (scanner ignores plugin without src/)\n");
echo "routes/admin.php: " . (is_file($pluginDir . '/routes/admin.php') ? "yes\n" : "MISSING\n");
echo "StruxaCatalogAdminRouteRegistrar.php: " . (is_file($root . '/src/Plugin/StruxaCatalogAdminRouteRegistrar.php') ? "yes\n" : "MISSING (curl from GitHub 1.1.58+)\n");

echo "\nRegistrar gates (same checks as core):\n";
$scanner = new \App\Plugin\PluginScanner($root);
$discovered = $scanner->findBySlug('struxa-admin');
echo "  1. PluginScanner finds struxa-admin: " . ($discovered !== null ? "PASS\n" : "FAIL\n");
$plugins = new \App\Plugin\PluginRepository($pdo);
$dbRow = $plugins->findBySlug('struxa-admin');
echo "  2. PluginRepository DB row: " . ($dbRow !== null ? "PASS\n" : "FAIL (Repair or activate once)\n");
if ($discovered !== null) {
    \App\Plugin\PluginManager::registerPsr4Autoload($discovered);
    $catalogOk = class_exists(\StruxaAdmin\CatalogSettings::class);
    echo "  3. CatalogSettings autoload: " . ($catalogOk ? "PASS\n" : "FAIL (broken plugin src/ or plugin.json psr4)\n");
}

$registrarPath = $root . '/src/Plugin/StruxaCatalogAdminRouteRegistrar.php';
if (is_file($registrarPath)) {
    $src = file_get_contents($registrarPath);
    $ok = is_string($src) && str_contains($src, 'registerPsr4Autoload($discovered)')
        && str_contains($src, 'class_exists(CatalogSettings::class)');
    $order = is_string($src) && strpos($src, 'registerPsr4Autoload') < strpos($src, 'class_exists(CatalogSettings');
    echo "Registrar autoload before class_exists: " . ($order ? "yes (1.1.58+)\n" : "NO — update file from GitHub\n");
}

echo "\nPre-bootstrap checks:\n";
echo "  PluginManager::registerStruxaCatalogAdminRoutesIfNeeded: "
    . (method_exists(\App\Plugin\PluginManager::class, 'registerStruxaCatalogAdminRoutesIfNeeded') ? "yes\n" : "NO — curl src/Plugin/PluginManager.php\n");
echo "  StruxaCatalogAdminRouteRegistrar class: "
    . (class_exists(\App\Plugin\StruxaCatalogAdminRouteRegistrar::class) ? "yes\n" : "NO — broken registrar file or autoload\n");

$scanner = new \App\Plugin\PluginScanner($root);
$discovered = $scanner->findBySlug('struxa-admin');
if ($discovered !== null) {
    \App\Plugin\PluginManager::registerPsr4Autoload($discovered);
    echo "  CatalogSettings (pre-bootstrap): "
        . (class_exists(\StruxaAdmin\CatalogSettings::class) ? "yes\n" : "NO\n");
}

echo "\nBootstrap routes:\n";
$_SERVER['REQUEST_URI'] = '/admin';
$_SERVER['REQUEST_METHOD'] = 'GET';

try {
    $app = require $root . '/bootstrap/web_app.php';
} catch (Throwable $e) {
    echo "BOOTSTRAP FAILED: " . $e->getMessage() . "\n" . $e->getFile() . ':' . $e->getLine() . "\n";
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
    echo "Registrar skipReason after bootstrap: " . ($skip ?? 'none (register() may have thrown — check error_log)') . "\n";
}

$admin = 0;
foreach ($app->getRouteCollector()->getRoutes() as $route) {
    $name = $route->getName();
    if (!is_string($name) || !str_contains($name, 'struxa_catalog')) {
        continue;
    }
    echo "  $name\n";
    if (str_starts_with($name, 'admin.struxa_catalog')) {
        $admin++;
    }
}

echo $admin > 0
    ? "\nOK: admin catalog routes are registered.\n"
    : "\nPROBLEM: no admin.struxa_catalog.* routes.\nCheck error_log for [plugin] Boot failed / Core catalog admin routes failed.\n";

$row2 = $pdo->query("SELECT slug, is_active FROM cms_plugins WHERE slug = 'struxa-admin'")->fetch();
if (is_array($row2) && (int) $row2['is_active'] === 0) {
    echo "\nNote: is_active=0 after this run — circuit breaker may have fired.\n";
    echo "Set PLUGIN_BOOT_CIRCUIT_BREAKER=0 in .env, activate plugin, update registrar (1.1.58+).\n";
}
