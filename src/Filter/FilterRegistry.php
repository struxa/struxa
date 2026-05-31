<?php

declare(strict_types=1);

namespace App\Filter;

use App\Plugin\PluginPerformanceRegistry;

/**
 * WordPress-style filter pipeline: callbacks transform a value in priority order.
 */
final class FilterRegistry
{
    /** @var array<string, list<array{priority: int, callback: callable(mixed, array<string, mixed>): mixed, plugin_slug: ?string}>> */
    private array $filters = [];

    /**
     * @param callable(mixed, array<string, mixed>): mixed $callback
     */
    public function add(string $hook, callable $callback, int $priority = 10, ?string $pluginSlug = null): void
    {
        $this->filters[$hook][] = [
            'priority' => $priority,
            'callback' => $callback,
            'plugin_slug' => $pluginSlug,
        ];
    }

    /**
     * @param array<string, mixed> $context
     */
    public function apply(string $hook, mixed $value, array $context = []): mixed
    {
        $items = $this->filters[$hook] ?? [];
        if ($items === []) {
            return $value;
        }

        usort($items, static fn (array $a, array $b): int => $a['priority'] <=> $b['priority']);

        $perf = PluginPerformanceRegistry::instanceOrNull();
        foreach ($items as $item) {
            $start = hrtime(true);
            $value = ($item['callback'])($value, $context);
            if ($perf !== null) {
                $ms = (hrtime(true) - $start) / 1_000_000;
                $perf->recordHookCall('filter:' . $hook, $ms, $item['plugin_slug']);
            }
        }

        return $value;
    }

    public function has(string $hook): bool
    {
        return ($this->filters[$hook] ?? []) !== [];
    }

    /**
     * @return array<string, int> plugin slug => filter callback count
     */
    public function countByPlugin(): array
    {
        $counts = [];
        foreach ($this->filters as $items) {
            foreach ($items as $item) {
                $slug = $item['plugin_slug'] ?? 'core';
                $counts[$slug] = ($counts[$slug] ?? 0) + 1;
            }
        }

        return $counts;
    }
}
