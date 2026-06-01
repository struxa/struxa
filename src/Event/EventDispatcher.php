<?php

declare(strict_types=1);

namespace App\Event;

use App\Plugin\PluginPerformanceRegistry;
use App\Support\ClassUtil;

/**
 * Typed event bus: listeners register by concrete event class name.
 */
final class EventDispatcher
{
    /** @var array<class-string, list<array{callback: callable(object): void, plugin_slug: ?string}>> */
    private array $listeners = [];

    /**
     * @param class-string $eventClass
     * @param callable(object): void $listener
     */
    public function listen(string $eventClass, callable $listener, ?string $pluginSlug = null): void
    {
        $this->listeners[$eventClass][] = [
            'callback' => $listener,
            'plugin_slug' => $pluginSlug,
        ];
    }

    public function dispatch(object $event): void
    {
        $class = $event::class;
        $perf = PluginPerformanceRegistry::instanceOrNull();
        foreach ($this->listeners[$class] ?? [] as $item) {
            $start = hrtime(true);
            ($item['callback'])($event);
            if ($perf !== null) {
                $ms = (hrtime(true) - $start) / 1_000_000;
                $perf->recordHookCall('event:' . ClassUtil::shortName($class), $ms, $item['plugin_slug']);
            }
        }
    }

    /**
     * @return array<string, int> plugin slug => listener count
     */
    public function countByPlugin(): array
    {
        $counts = [];
        foreach ($this->listeners as $items) {
            foreach ($items as $item) {
                $slug = $item['plugin_slug'] ?? 'core';
                $counts[$slug] = ($counts[$slug] ?? 0) + 1;
            }
        }

        return $counts;
    }
}
