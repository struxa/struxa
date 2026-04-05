<?php

declare(strict_types=1);

namespace App\Plugin;

/**
 * Collects admin nav contributions from booted plugins (cleared each request before boot).
 */
final class PluginAdminNavRegistry
{
    private static ?self $instance = null;

    /** @var list<array{plugin_slug: string, label: string, route_name: string, route_params: array<string, string>}> */
    private array $items = [];

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public static function resetInstance(): void
    {
        self::$instance = null;
    }

    public function clear(): void
    {
        $this->items = [];
    }

    /**
     * @param array<string, string> $routeParams
     */
    public function register(string $pluginSlug, string $label, string $routeName, array $routeParams = []): void
    {
        $this->items[] = [
            'plugin_slug' => $pluginSlug,
            'label' => $label,
            'route_name' => $routeName,
            'route_params' => $routeParams,
        ];
    }

    /**
     * @return list<array{plugin_slug: string, label: string, route_name: string, route_params: array<string, string>}>
     */
    public function all(): array
    {
        return $this->items;
    }
}
