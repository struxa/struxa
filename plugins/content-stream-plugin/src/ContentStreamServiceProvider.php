<?php

declare(strict_types=1);

namespace ContentStreamPlugin;

use App\Plugin\PluginBootContext;
use App\Plugin\PluginServiceProviderInterface;

final class ContentStreamServiceProvider implements PluginServiceProviderInterface
{
    public function boot(PluginBootContext $context): void
    {
        $context->registerPluginReservedSlugs(['content-stream']);

        $context->registerAdminNavItem('API settings', 'plugin.content_stream_plugin.admin');
        $context->registerAdminNavItem('Domain tool', 'plugin.content_stream_plugin.tool');
    }
}
