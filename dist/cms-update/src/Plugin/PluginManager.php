<?php

declare(strict_types=1);

namespace App\Plugin;

use App\Event\EventDispatcher;
use App\Event\PluginBootedEvent;
use PHPAuth\Auth;
use Slim\App;
use Slim\Views\Twig;
use Twig\Loader\FilesystemLoader;

/**
 * Discovers plugins, registers autoload + Twig namespaces, loads route files and service providers.
 */
final class PluginManager
{
    public function __construct(
        private readonly string $projectRoot,
        private readonly PluginRepository $plugins,
        private readonly PluginScanner $scanner,
        private readonly PluginValidator $validator,
    ) {
    }

    /**
     * Prepare contexts and register plugin public routes. Must run before core variable routes
     * (e.g. /{typeSlug}/{entrySlug}) so plugin static paths are not shadowed.
     *
     * @param callable(): array<string, mixed> $viewData
     *
     * @return list<PluginBootContext> Pass to registerActiveAdminRoutes() and bootActivePluginLifecycle()
     */
    public function registerActivePublicRoutes(App $app, Twig $twig, Auth $auth, \PDO $pdo, callable $viewData, EventDispatcher $events): array
    {
        $contexts = $this->createActiveContexts($app, $twig, $auth, $pdo, $viewData, $events);
        foreach ($contexts as $ctx) {
            $this->loadRouteFile($ctx->pluginRoot() . '/routes/public.php', $app, $ctx);
        }

        return $contexts;
    }

    /**
     * Plugin admin routes must register before public `/{typeSlug}/{entrySlug}` or URLs like
     * /admin/hello-plugin are claimed by the variable route and FastRoute rejects static duplicates.
     *
     * @param list<PluginBootContext> $contexts
     */
    public function registerActiveAdminRoutes(App $app, array $contexts): void
    {
        foreach ($contexts as $ctx) {
            $this->loadRouteFile($ctx->pluginRoot() . '/routes/admin.php', $app, $ctx);
        }
    }

    /**
     * Service providers (nav, listeners) and plugin boot events — run after all routes are registered.
     *
     * @param list<PluginBootContext> $contexts
     */
    public function bootActivePluginLifecycle(array $contexts, EventDispatcher $events): void
    {
        PluginAdminNavRegistry::instance()->clear();

        foreach ($contexts as $ctx) {
            $main = $ctx->manifest->mainClass;
            if ($main !== null && class_exists($main)) {
                $provider = new $main();
                if ($provider instanceof PluginServiceProviderInterface) {
                    $provider->boot($ctx);
                }
            }

            $events->dispatch(new PluginBootedEvent($ctx->manifest->slug));
        }
    }

    /**
     * @param callable(): array<string, mixed> $viewData
     *
     * @return list<PluginBootContext>
     */
    private function createActiveContexts(App $app, Twig $twig, Auth $auth, \PDO $pdo, callable $viewData, EventDispatcher $events): array
    {
        $loader = $twig->getEnvironment()->getLoader();
        if (!$loader instanceof FilesystemLoader) {
            return [];
        }

        $out = [];
        foreach ($this->plugins->activeSlugs() as $slug) {
            $discovered = $this->scanner->findBySlug($slug);
            if ($discovered === null) {
                continue;
            }

            $this->registerAutoloadForPlugin($discovered);
            $views = $discovered->rootPath . '/views';
            if (is_dir($views)) {
                $loader->addPath($views, self::twigNamespaceForSlug($slug));
            }

            $out[] = new PluginBootContext(
                $this->projectRoot,
                $discovered->rootPath,
                $discovered->manifest,
                $pdo,
                $app,
                $twig,
                $viewData,
                $auth,
                $events,
                PluginAdminNavRegistry::instance(),
            );
        }

        return $out;
    }

    public static function twigNamespaceForSlug(string $slug): string
    {
        return 'plugin_' . str_replace('-', '_', $slug);
    }

    public function registerAutoloadForPlugin(DiscoveredPlugin $plugin): void
    {
        $psr4 = $plugin->manifest->autoloadPsr4;
        if ($psr4 === null) {
            return;
        }
        $base = $plugin->rootPath . '/';
        foreach ($psr4 as $prefix => $relative) {
            $dir = $base . trim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative), DIRECTORY_SEPARATOR);
            if (!is_dir($dir)) {
                continue;
            }
            $prefix = rtrim($prefix, '\\') . '\\';
            spl_autoload_register(static function (string $class) use ($prefix, $dir): void {
                if (!str_starts_with($class, $prefix)) {
                    return;
                }
                $rel = substr($class, strlen($prefix));
                $file = $dir . '/' . str_replace('\\', DIRECTORY_SEPARATOR, $rel) . '.php';
                if (is_file($file)) {
                    require_once $file;
                }
            });
        }
    }

    private function loadRouteFile(string $path, App $app, PluginBootContext $ctx): void
    {
        if (!is_file($path)) {
            return;
        }
        $callback = require $path;
        if (!is_callable($callback)) {
            return;
        }
        $callback($app, $ctx);
    }

    /**
     * Sync DB rows from disk manifests (admin index).
     *
     * @return list<DiscoveredPlugin>
     */
    public function syncDiscoveredToDatabase(): array
    {
        $list = $this->scanner->discover();
        foreach ($list as $p) {
            $this->plugins->upsertFromManifest($p->manifest);
        }

        return $list;
    }
}
