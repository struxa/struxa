<?php

declare(strict_types=1);

namespace App\Event;

/**
 * Application-wide event access so core routes stay free of extra constructor injection.
 */
final class Events
{
    private static ?EventDispatcher $dispatcher = null;

    public static function set(EventDispatcher $dispatcher): void
    {
        self::$dispatcher = $dispatcher;
    }

    public static function dispatch(object $event): void
    {
        self::$dispatcher?->dispatch($event);
    }

    public static function dispatcher(): ?EventDispatcher
    {
        return self::$dispatcher;
    }
}
