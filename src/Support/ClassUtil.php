<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Class name helpers (no Laravel dependency).
 */
final class ClassUtil
{
    /**
     * Short class name without namespace, e.g. App\Event\FooEvent → FooEvent.
     *
     * @param class-string|object $class
     */
    public static function shortName(string|object $class): string
    {
        if (is_object($class)) {
            $class = $class::class;
        }
        $pos = strrpos($class, '\\');

        return $pos === false ? $class : substr($class, $pos + 1);
    }
}
