<?php

declare(strict_types=1);

namespace App\Plugin;

/**
 * Plugin entry point: register listeners, nav, and other integrations in boot().
 */
interface PluginServiceProviderInterface
{
    public function boot(PluginBootContext $context): void;
}
