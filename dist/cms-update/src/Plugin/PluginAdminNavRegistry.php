<?php

declare(strict_types=1);

namespace App\Plugin;

/**
 * Collects admin nav contributions from booted plugins (cleared each request before boot).
 */
final class PluginAdminNavRegistry
{
    private static ?self $instance = null;

    /** @var list<array{plugin_slug: string, label: string, route_name: string, route_params: array<string, string>, parent_plugin_slug: ?string}> */
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
     * @param bool                  $allowSelfAsParent When true, {@code $parentPluginSlug} may equal
     *                                                {@code $pluginSlug} so the grouper nests links under this plugin's manifest name.
     */
    public function register(
        string $pluginSlug,
        string $label,
        string $routeName,
        array $routeParams = [],
        ?string $parentPluginSlug = null,
        bool $allowSelfAsParent = false,
    ): void {
        $parent = $parentPluginSlug !== null ? trim($parentPluginSlug) : '';
        if ($parent === '' || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $parent)) {
            $parent = null;
        } elseif (strcasecmp($parent, $pluginSlug) === 0 && !$allowSelfAsParent) {
            $parent = null;
        }

        $this->items[] = [
            'plugin_slug' => $pluginSlug,
            'label' => $label,
            'route_name' => $routeName,
            'route_params' => $routeParams,
            'parent_plugin_slug' => $parent,
        ];
    }

    /**
     * @return list<array{plugin_slug: string, label: string, route_name: string, route_params: array<string, string>, parent_plugin_slug: ?string}>
     */
    public function all(): array
    {
        return $this->items;
    }
}
