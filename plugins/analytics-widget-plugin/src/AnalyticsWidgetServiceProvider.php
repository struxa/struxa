<?php

declare(strict_types=1);

namespace AnalyticsWidget;

use App\Plugin\PluginBootContext;
use App\Plugin\PluginServiceProviderInterface;

final class AnalyticsWidgetServiceProvider implements PluginServiceProviderInterface
{
    public function boot(PluginBootContext $context): void
    {
        $context->registerAdminNavItem('Analytics snippet', 'plugin.analytics_widget_plugin.admin');
    }
}
