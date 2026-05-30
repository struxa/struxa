<?php

declare(strict_types=1);

namespace App\Plugin;

use App\Content\ReservedContentSlugs;
use App\Event\EventDispatcher;
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
        return $this->auth;
    }

    public function events(): EventDispatcher
    {
        return $this->events;
    }

    /**
     * Register an extra admin sidebar link (under Extensions).
     *
     * When this plugin's {@code plugin.json} sets {@code parent_plugin} to another plugin's
     * directory slug, the link is nested under that parent's label in the sidebar (expandable group).
     * When {@code nested_admin_nav} is true, links are nested under this plugin's own {@code name}
     * instead of listing flat under Extensions.
     */
    public function registerAdminNavItem(string $label, string $routeName, array $routeParams = []): void
    {
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
     * Reserve first-path URL segments so they cannot be used as content type slugs.
     *
     * Call for each segment your plugin serves publicly, e.g. ['my-catalog'] for GET /my-catalog.
     * Do not add site-specific slugs to Struxa core — register them here in your plugin's boot().
     *
     * @param list<string> $slugs
     */
    public function registerPluginReservedSlugs(array $slugs): void
    {
        ReservedContentSlugs::registerPluginReservedSlugs($slugs);
    }

    /**
     * @param list<string> $slugs
     */
    public function registerReservedContentSlugs(array $slugs): void
    {
        ReservedContentSlugs::registerReservedContentSlugs($slugs);
    }

    /**
     * Register page builder block types from this plugin.
     */
    public function registerSectionProvider(SectionDefinitionProviderInterface $provider): void
    {
        SectionDefinitionRegistry::instance()->registerProvider($provider);
    }
}
