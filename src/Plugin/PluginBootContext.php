<?php

declare(strict_types=1);

namespace App\Plugin;

use App\Content\ReservedContentSlugs;
use App\Event\EventDispatcher;
use App\Filter\Filters;
use App\Jobs\Jobs;
use App\Section\SectionDefinitionProviderInterface;
use App\Section\SectionDefinitionRegistry;
use PHPAuth\Auth;
use Slim\App;
use Slim\Views\Twig;

/**
 * Everything an active plugin may use during boot (routes, Twig, events, admin nav).
 */
final class PluginBootContext
{
    private readonly PluginCapabilityGuard $capabilityGuard;

    public function __construct(
        private readonly string $projectRoot,
        private readonly string $pluginRoot,
        public readonly PluginManifest $manifest,
        private readonly \PDO $pdo,
        private readonly App $app,
        private readonly Twig $twig,
        /** @var callable(): array<string, mixed> */
        private readonly mixed $viewData,
        private readonly Auth $auth,
        private readonly EventDispatcher $events,
        private readonly PluginAdminNavRegistry $adminNav,
    ) {
        $this->capabilityGuard = new PluginCapabilityGuard($manifest);
    }

    public function capabilityGuard(): PluginCapabilityGuard
    {
        return $this->capabilityGuard;
    }

    public function projectRoot(): string
    {
        return $this->projectRoot;
    }

    public function pluginRoot(): string
    {
        return $this->pluginRoot;
    }

    public function pdo(): \PDO
    {
        if (!$this->capabilityGuard->isLegacyPermissive()
            && !$this->capabilityGuard->has(PluginCapability::DATABASE_READ)
            && !$this->capabilityGuard->has(PluginCapability::DATABASE_WRITE)) {
            throw new PluginCapabilityException(
                'Plugin "' . $this->manifest->slug . '" requires database.read or database.write in plugin.json to use pdo().'
            );
        }

        return $this->pdo;
    }

    public function app(): App
    {
        return $this->app;
    }

    public function twig(): Twig
    {
        return $this->twig;
    }

    /**
     * @return array<string, mixed>
     */
    public function viewData(array $extra = []): array
    {
        return array_merge(($this->viewData)(), $extra);
    }

    public function auth(): Auth
    {
        $this->capabilityGuard->assertCapability(PluginCapability::USER_READ);

        return $this->auth;
    }

    public function events(): EventDispatcher
    {
        return $this->events;
    }

    /**
     * Writable storage under {@code storage/plugins/{slug}/} (requires filesystem.write).
     */
    public function pluginStoragePath(string ...$segments): string
    {
        $this->capabilityGuard->assertCapability(PluginCapability::FILESYSTEM_WRITE);
        $base = $this->projectRoot . '/storage/plugins/' . $this->manifest->slug;
        if (!is_dir($base)) {
            @mkdir($base, 0755, true);
        }
        $path = $base;
        foreach ($segments as $segment) {
            $segment = trim(str_replace(['\\', '..'], '', $segment), '/');
            if ($segment === '') {
                continue;
            }
            $path .= '/' . $segment;
        }

        return $path;
    }

    /**
     * Register a filter callback (WordPress-style {@code apply_filters}).
     *
     * Use {@see \App\Filter\FilterHook} constants for hook names. Lower priority runs first (default 10).
     *
     * @param callable(mixed, array<string, mixed>): mixed $callback
     */
    public function addFilter(string $hook, callable $callback, int $priority = 10): void
    {
        $this->capabilityGuard->assertFilterRegistration($hook);
        Filters::add($hook, $callback, $priority, $this->manifest->slug);
    }

    /**
     * Listen for a core event. Declare the event short name in plugin.json {@code hooks.events}.
     *
     * @param class-string $eventClass
     * @param callable(object): void $listener
     */
    public function listenEvent(string $eventClass, callable $listener): void
    {
        $short = class_basename($eventClass);
        $resolved = PluginKnownEvents::resolveClass($short);
        if ($resolved === null || $resolved !== $eventClass) {
            throw new PluginCapabilityException(
                'Event class must be a known Struxa event registered in ' . PluginKnownEvents::class . '.'
            );
        }
        $this->capabilityGuard->assertEventRegistration($eventClass, $short);
        $this->events->listen($eventClass, $listener, $this->manifest->slug);
    }

    /**
     * Register a background job handler (processed by {@code php bin/cms.php jobs:work}).
     *
     * @param callable(\App\Jobs\Job, \App\Jobs\JobHandlerContext): array<string, mixed> $handler
     */
    public function registerJobHandler(string $type, callable $handler): void
    {
        $this->capabilityGuard->assertCapability(PluginCapability::DATABASE_WRITE);
        Jobs::registerHandler($type, $handler);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function enqueueJob(string $type, array $payload = [], ?string $dedupeKey = null): int
    {
        $this->capabilityGuard->assertCapability(PluginCapability::DATABASE_WRITE);

        return Jobs::enqueue($type, $payload, $dedupeKey);
    }

    /**
     * Register an extra admin sidebar link (under Extensions).
     */
    public function registerAdminNavItem(string $label, string $routeName, array $routeParams = []): void
    {
        $this->capabilityGuard->assertCapability(PluginCapability::ADMIN_NAV);
        $nested = $this->manifest->nestedAdminNav;
        $parentForNav = $nested ? $this->manifest->slug : $this->manifest->parentPluginSlug;
        $this->adminNav->register(
            $this->manifest->slug,
            $label,
            $routeName,
            $routeParams,
            $parentForNav,
            $nested,
        );
    }

    /**
     * @param list<string> $slugs
     */
    public function registerPluginReservedSlugs(array $slugs): void
    {
        $this->capabilityGuard->assertCapability(PluginCapability::FRONTEND_RENDER);
        ReservedContentSlugs::registerPluginReservedSlugs($slugs);
    }

    /**
     * @param list<string> $slugs
     */
    public function registerReservedContentSlugs(array $slugs): void
    {
        $this->capabilityGuard->assertCapability(PluginCapability::FRONTEND_RENDER);
        ReservedContentSlugs::registerReservedContentSlugs($slugs);
    }

    public function registerSectionProvider(SectionDefinitionProviderInterface $provider): void
    {
        $this->capabilityGuard->assertCapability(PluginCapability::FRONTEND_RENDER);
        SectionDefinitionRegistry::instance()->registerProvider($provider);
    }
}
