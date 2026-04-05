<?php

declare(strict_types=1);

namespace FootballHubPlugin;

use App\Plugin\PluginBootContext;
use App\Plugin\PluginServiceProviderInterface;

final class FootballHubPluginServiceProvider implements PluginServiceProviderInterface
{
    public function boot(PluginBootContext $context): void
    {
        $context->registerAdminNavItem('Football hub', 'plugin.football_hub_plugin.admin');
    }
}
