<?php

declare(strict_types=1);

namespace SeoHelper;

use App\Plugin\PluginBootContext;
use App\Plugin\PluginServiceProviderInterface;

final class SeoHelperServiceProvider implements PluginServiceProviderInterface
{
    public function boot(PluginBootContext $context): void
    {
        $context->registerAdminNavItem('SEO helper', 'plugin.seo_helper_plugin.admin');
    }
}
