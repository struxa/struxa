<?php

declare(strict_types=1);

namespace App\Filter;

/**
 * Application-wide filter access (mirrors {@see \App\Event\Events}).
 */
final class Filters
{
    private static ?FilterRegistry $registry = null;

    public static function set(FilterRegistry $registry): void
    {
        self::$registry = $registry;
    }

    /**
     * @param callable(mixed, array<string, mixed>): mixed $callback
     */
    public static function add(string $hook, callable $callback, int $priority = 10, ?string $pluginSlug = null): void
    {
        self::$registry?->add($hook, $callback, $priority, $pluginSlug);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function apply(string $hook, mixed $value, array $context = []): mixed
    {
        return self::$registry?->apply($hook, $value, $context) ?? $value;
    }

    public static function registry(): ?FilterRegistry
    {
        return self::$registry;
    }
}
