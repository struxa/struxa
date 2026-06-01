<?php

declare(strict_types=1);

namespace App\Cli;

use App\CmsVersion;
use App\Plugin\PluginRepository;
use App\Plugin\PluginScanner;
use Slim\App;

/**
 * CLI diagnostics for plugin admin route registration (struxa-admin / catalog).
 */
final class PluginRouteDiagnose
{
    public static function run(string $root, string $slug = 'struxa-admin'): int
    {
        $slug = strtolower(trim($slug));
        fwrite(STDOUT, 'Struxa CMS ' . CmsVersion::CURRENT . "\n");
        fwrite(STDOUT, "Diagnosing plugin: {$slug}\n\n");

        $pdo = CmsCliBootstrap::connectDatabase($root);
        $scanner = new PluginScanner($root);
        $repo = new PluginRepository($pdo);

        $row = $repo->findBySlug($slug);
        fwrite(STDOUT, 'Database: ' . ($row === null
            ? "no row\n"
            : ('slug=' . $row->slug . ' is_active=' . ($row->isActive ? '1' : '0') . "\n")));

        $discovered = $scanner->findBySlug($slug);
        if ($discovered === null) {
            fwrite(STDOUT, "Disk: plugin folder not found under plugins/\n");

            return 1;
        }
        fwrite(STDOUT, 'Disk: ' . $discovered->rootPath . "\n");
        fwrite(STDOUT, 'Manifest version: ' . $discovered->manifest->version . "\n");

        $adminRouteFile = $discovered->rootPath . '/routes/admin.php';
        if (!is_file($adminRouteFile)) {
            fwrite(STDOUT, "routes/admin.php: MISSING\n");

            return 1;
        }
        fwrite(STDOUT, "routes/admin.php: present\n");

        try {
            $callback = require $adminRouteFile;
        } catch (\Throwable $e) {
            fwrite(STDERR, 'require admin.php failed: ' . $e->getMessage() . "\n");

            return 1;
        }
        if (!is_callable($callback)) {
            fwrite(STDERR, 'require admin.php did not return a callable (got ' . gettype($callback) . ").\n");
            fwrite(STDERR, "Fix: ensure routes/admin.php ends with `return static function (...) { ... };`\n");

            return 1;
        }
        fwrite(STDOUT, "routes/admin.php: returns callable\n\n");

        $_SERVER['REQUEST_URI'] = '/admin';
        $_SERVER['REQUEST_METHOD'] = 'GET';

        try {
            /** @var App $app */
            $app = require $root . '/bootstrap/web_app.php';
        } catch (\Throwable $e) {
            fwrite(STDERR, 'Bootstrap failed: ' . $e->getMessage() . "\n");
            fwrite(STDERR, $e->getFile() . ':' . $e->getLine() . "\n");

            return 1;
        }

        $names = [];
        foreach ($app->getRouteCollector()->getRoutes() as $route) {
            $name = $route->getName();
            if (is_string($name) && str_contains($name, 'struxa_catalog')) {
                $names[] = $name;
            }
        }

        if ($names === []) {
            fwrite(STDERR, "NO registered routes matching *struxa_catalog*.\n");
            fwrite(STDERR, "Check: is_active=1 in cms_plugins, PluginManager registerActiveAdminRoutes, PHP error log.\n");

            return 1;
        }

        fwrite(STDOUT, "Registered routes:\n");
        foreach ($names as $name) {
            fwrite(STDOUT, '  - ' . $name . "\n");
        }

        return 0;
    }
}
