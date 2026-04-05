<?php

declare(strict_types=1);

namespace App\Event;

final class PluginBootedEvent
{
    public function __construct(
        public readonly string $pluginSlug,
    ) {
    }
}
