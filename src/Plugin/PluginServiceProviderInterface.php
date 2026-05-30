<?php

declare(strict_types=1);

namespace App\Plugin;

/**
 * Plugin entry point: register listeners, nav, reserved URL segments, and other integrations in boot().
 *
 * Plugins with public routes under /{segment}/… should call
 * {@see PluginBootContext::registerPluginReservedSlugs()} so content types cannot claim that segment.
 *
 * Page builder blocks: implement {@see \App\Section\SectionDefinitionProviderInterface} and call
 * {@see PluginBootContext::registerSectionProvider()} from boot().
 */
interface PluginServiceProviderInterface
{
    public function boot(PluginBootContext $context): void;
}
