<?php

declare(strict_types=1);

namespace App\Event;

/**
 * Typed event bus: listeners register by concrete event class name.
 */
final class EventDispatcher
{
    /** @var array<class-string, list<callable(object): void>> */
    private array $listeners = [];

    /**
     * @param class-string $eventClass
     * @param callable(object): void $listener
     */
    public function listen(string $eventClass, callable $listener): void
    {
        $this->listeners[$eventClass][] = $listener;
    }

    public function dispatch(object $event): void
    {
        $class = $event::class;
        foreach ($this->listeners[$class] ?? [] as $listener) {
            $listener($event);
        }
    }
}
