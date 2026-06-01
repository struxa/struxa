<?php

declare(strict_types=1);

/**
 * One-shot check for struxa-admin catalog admin route registration (cPanel-safe).
 * Run: php scripts/verify-catalog-admin-routes.php
 */

const VERIFY_SCRIPT_VERSION = '2026-06-01b';

$root = dirname(__DIR__);
chdir($root);

require $root . '/vendor/autoload.php';

if (is_readable($root . '/.env')) {
    Dotenv\Dotenv::createImmutable($root)->safeLoad();
}

$registrarFile = $root . '/src/Plugin/StruxaCatalogAdminRouteRegistrar.php';
$registrarSrc = is_file($registrarFile) ? (string) file_get_contents($registrarFile) : '';

echo "=== Catalog admin route verify (" . VERIFY_SCRIPT_VERSION . ") ===\n\n";

echo 'registerPsr4Autoload in registrar: '
    . (str_contains($registrarSrc, 'PluginManager::registerPsr4Autoload($discovered)') ? "yes\n" : "NO — curl registrar from GitHub main\n");

$hasAuthParamFix = preg_match(
    '/function register\s*\(\s*App\s+\$app\s*,\s*PluginBootContext\s+\$ctx\s*,\s*Auth\s+\$auth\s*\)/',
    $registrarSrc
) === 1;
echo 'register(..., Auth $auth) signature (user.read fix): '
    . ($hasAuthParamFix ? "yes\n" : "NO — curl latest StruxaCatalogAdminRouteRegistrar.php\n");

$usesCtxAuth = preg_match('/\$auth\s*=\s*\$ctx->auth\s*\(\s*\)/', $registrarSrc) === 1;
echo 'uses $ctx->auth() in register(): '
    . ($usesCtxAuth ? "BAD — old registrar\n" : "no\n");

$pluginJson = $root . '/plugins/struxa-admin/plugin.json';
$pluginSrc = is_file($pluginJson) ? (string) file_get_contents($pluginJson) : '';
echo 'plugin.json has user.read: '
    . (str_contains($pluginSrc, 'user.read') ? "yes\n" : "no (optional if registrar has Auth fix)\n");

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

    $last = \App\Plugin\StruxaCatalogAdminRouteRegistrar::lastRegisterError();
    if (is_string($last) && $last !== '') {
        echo 'lastRegisterError: ' . $last . "\n";
    }
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

    $logCandidates = [
        $root . '/error_log',
        dirname($root) . '/error_log',
        '/home/bushell/logs/error_log',
    ];
    foreach ($logCandidates as $logPath) {
        if (!is_readable($logPath)) {
            continue;
        }
        $lines = [];
        foreach (file($logPath, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (str_contains($line, 'catalog admin') || str_contains($line, 'Catalog admin')) {
                $lines[] = $line;
            }
        }
        if ($lines !== []) {
            echo "\nRecent catalog lines from $logPath:\n";
            foreach (array_slice($lines, -5) as $line) {
                echo '  ' . $line . "\n";
            }
            break;
        }
    }

    if (!$hasAuthParamFix) {
        echo "\nFIX: curl -fsSL https://raw.githubusercontent.com/struxa/struxa/main/src/Plugin/StruxaCatalogAdminRouteRegistrar.php -o src/Plugin/StruxaCatalogAdminRouteRegistrar.php\n";
    }

    exit(1);
}

echo "\nRegistered:\n";
foreach ($admin as $name) {
    echo "  $name\n";
}
echo "\nOK\n";
