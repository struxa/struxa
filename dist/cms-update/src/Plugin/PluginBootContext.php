<?php

declare(strict_types=1);

namespace App\Plugin;

use App\Event\EventDispatcher;
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
     */
    public function registerAdminNavItem(string $label, string $routeName, array $routeParams = []): void
    {
        $this->adminNav->register($this->manifest->slug, $label, $routeName, $routeParams);
    }
}
