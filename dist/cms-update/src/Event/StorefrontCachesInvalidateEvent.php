<?php

declare(strict_types=1);

namespace App\Event;

/**
 * Dispatched when public storefront output or shared Twig globals may have changed.
 */
final class StorefrontCachesInvalidateEvent
{
    public function __construct(
        public readonly string $reason = '',
    ) {
    }
}
