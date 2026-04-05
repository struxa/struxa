<?php

declare(strict_types=1);

namespace StripeStorePlugin;

use App\Plugin\PluginBootContext;
use App\Plugin\PluginServiceProviderInterface;

final class StripeStorePluginServiceProvider implements PluginServiceProviderInterface
{
    public function boot(PluginBootContext $context): void
    {
        $context->registerAdminNavItem('Stripe store', 'plugin.stripe_store_plugin.admin');
    }
}
