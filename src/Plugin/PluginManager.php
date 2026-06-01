<?php

declare(strict_types=1);

namespace App\Plugin;

use App\Event\EventDispatcher;
use App\Event\PluginBootedEvent;
use App\Filter\Filters;
use PHPAuth\Auth;
use Slim\App;
use Slim\Views\Twig;
use Twig\Loader\FilesystemLoader;

/**
 * Discovers plugins, registers autoload + Twig namespaces, loads route files and service providers.
 */
final class PluginManager
{
    /** @var array<string, true> */
    private static array $autoloadRegisteredForSlug = [];

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
    public function registerActivePublicRoutes(
        App $app,
        Twig $twig,
        Auth $auth,
        \PDO $pdo,
        callable $viewData,
        EventDispatcher $events,
        PluginLoadScope $scope = PluginLoadScope::Public,
    ): array {
        $contexts = $this->createActiveContexts($app, $twig, $auth, $pdo, $viewData, $events, $scope);
        foreach ($contexts as $ctx) {
            try {
                $this->loadRouteFile($ctx->pluginRoot() . '/routes/public.php', $app, $ctx, true);
            } catch (PluginCapabilityException $e) {
                error_log('[plugin] Skipped public routes for ' . $ctx->manifest->slug . ': ' . $e->getMessage());
            }
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
            try {
                $this->loadRouteFile($ctx->pluginRoot() . '/routes/admin.php', $app, $ctx, false);
            } catch (PluginCapabilityException $e) {
                error_log('[plugin] Skipped admin routes for ' . $ctx->manifest->slug . ': ' . $e->getMessage());
            }
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

        $filterCountsBefore = Filters::registry()?->countByPlugin() ?? [];
        $eventCountsBefore = $events->countByPlugin();

        foreach ($contexts as $ctx) {
            $slug = $ctx->manifest->slug;
            $start = hrtime(true);
            $booted = false;

            $main = $ctx->manifest->mainClass;
            if ($main !== null && class_exists($main)) {
                $provider = new $main();
                if ($provider instanceof PluginServiceProviderInterface) {
                    try {
                        $provider->boot($ctx);
                        $booted = true;
                    } catch (PluginCapabilityException $e) {
                        error_log('[plugin] Boot failed for ' . $slug . ': ' . $e->getMessage());
                        PluginPerformanceRegistry::instanceOrNull()?->recordBootError($slug, $e);
                    } catch (\Throwable $e) {
                        error_log('[plugin] Boot failed for ' . $slug . ': ' . $e->getMessage());
                        PluginPerformanceRegistry::instanceOrNull()?->recordBootError($slug, $e);
                        if (PluginPerformanceRegistry::circuitBreakerEnabled()) {
                            $this->plugins->setActive($slug, false);
                            PluginPerformanceRegistry::instanceOrNull()?->recordAutoDeactivated($slug);
                        }
                    }
                }
            }

            if ($booted || $main === null) {
                $ms = (hrtime(true) - $start) / 1_000_000;
                $filterCounts = Filters::registry()?->countByPlugin() ?? [];
                $eventCounts = $events->countByPlugin();
                $filterCount = ($filterCounts[$slug] ?? 0) - ($filterCountsBefore[$slug] ?? 0);
                $eventCount = ($eventCounts[$slug] ?? 0) - ($eventCountsBefore[$slug] ?? 0);
                PluginPerformanceRegistry::instanceOrNull()?->recordBoot($slug, $ms, max(0, $filterCount), max(0, $eventCount));
                $filterCountsBefore = $filterCounts;
                $eventCountsBefore = $eventCounts;
            }

            $events->dispatch(new PluginBootedEvent($slug));
        }
    }

    /**
     * @param callable(): array<string, mixed> $viewData
     *
     * @return list<PluginBootContext>
     */
    private function createActiveContexts(
        App $app,
        Twig $twig,
        Auth $auth,
        \PDO $pdo,
        callable $viewData,
        EventDispatcher $events,
        PluginLoadScope $scope,
    ): array {
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

            if (!$scope->allows($discovered->manifest)) {
                PluginPerformanceRegistry::instanceOrNull()?->recordSkipped($slug, $scope);

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
        self::registerPsr4Autoload($plugin);
    }

    /**
     * Register plugin.json psr4 autoload once per slug (safe before compatibility checks on inactive plugins).
     */
    public static function registerPsr4Autoload(DiscoveredPlugin $plugin): void
    {
        $slug = $plugin->manifest->slug;
        if (isset(self::$autoloadRegisteredForSlug[$slug])) {
            return;
        }
        self::$autoloadRegisteredForSlug[$slug] = true;

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

        self::preloadMainClassFile($plugin);
    }

    /**
     * Require the main_class file directly (compatibility checks run before spl_autoload may fire).
     */
    public static function preloadMainClassFile(DiscoveredPlugin $plugin): void
    {
        $class = $plugin->manifest->mainClass;
        if ($class === null || class_exists($class, false)) {
            return;
        }

        $psr4 = $plugin->manifest->autoloadPsr4;
        if ($psr4 === null) {
            return;
        }

        $base = $plugin->rootPath . DIRECTORY_SEPARATOR;
        foreach ($psr4 as $prefix => $relative) {
            $prefix = rtrim($prefix, '\\') . '\\';
            if (!str_starts_with($class, $prefix)) {
                continue;
            }
            $rel = substr($class, strlen($prefix));
            $dir = $base . trim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative), DIRECTORY_SEPARATOR);
            $file = $dir . DIRECTORY_SEPARATOR . str_replace('\\', DIRECTORY_SEPARATOR, $rel) . '.php';
            if (is_file($file)) {
                require_once $file;

                return;
            }
        }
    }

    /**
     * Human-readable hint when main_class cannot be loaded (for admin compatibility UI).
     */
    public static function mainClassLoadHint(DiscoveredPlugin $plugin): ?string
    {
        $class = $plugin->manifest->mainClass;
        if ($class === null) {
            return null;
        }

        $psr4 = $plugin->manifest->autoloadPsr4;
        if ($psr4 === null) {
            return 'plugin.json has no autoload.psr4.';
        }

        $base = $plugin->rootPath . DIRECTORY_SEPARATOR;
        foreach ($psr4 as $prefix => $relative) {
            $prefix = rtrim($prefix, '\\') . '\\';
            if (!str_starts_with($class, $prefix)) {
                continue;
            }
            $rel = substr($class, strlen($prefix));
            $dir = $base . trim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative), DIRECTORY_SEPARATOR);
            $file = $dir . DIRECTORY_SEPARATOR . str_replace('\\', DIRECTORY_SEPARATOR, $rel) . '.php';
            if (is_file($file)) {
                return null;
            }

            return 'Expected file missing: plugins/' . $plugin->manifest->slug . '/' . trim($relative, '/') . '/' . str_replace('\\', '/', $rel) . '.php';
        }

        return 'No PSR-4 prefix matches main_class namespace.';
    }

    private function loadRouteFile(string $path, App $app, PluginBootContext $ctx, bool $public): void
    {
        if (!is_file($path)) {
            return;
        }
        if ($public) {
            $ctx->capabilityGuard()->assertPublicRoutes();
        } else {
            $ctx->capabilityGuard()->assertAdminRoutes();
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
