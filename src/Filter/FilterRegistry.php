<?php

declare(strict_types=1);

namespace App\Filter;

/**
 * WordPress-style filter pipeline: callbacks transform a value in priority order.
 */
final class FilterRegistry
{
    /** @var array<string, list<array{priority: int, callback: callable(mixed, array<string, mixed>): mixed}>> */
    private array $filters = [];

    /**
     * @param callable(mixed, array<string, mixed>): mixed $callback
     */
    public function add(string $hook, callable $callback, int $priority = 10): void
    {
        $this->filters[$hook][] = [
            'priority' => $priority,
            'callback' => $callback,
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

        foreach ($items as $item) {
            $value = ($item['callback'])($value, $context);
        }

        return $value;
    }

    public function has(string $hook): bool
    {
        return ($this->filters[$hook] ?? []) !== [];
    }
}
