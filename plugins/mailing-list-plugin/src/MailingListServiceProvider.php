<?php

declare(strict_types=1);

namespace MailingListPlugin;

use App\Plugin\PluginBootContext;
use App\Plugin\PluginServiceProviderInterface;

final class MailingListServiceProvider implements PluginServiceProviderInterface
{
    public function boot(PluginBootContext $context): void
    {
        $context->registerPluginReservedSlugs(['mailing-list']);

        $context->registerAdminNavItem('Mailing lists', 'plugin.mailing_list_plugin.lists.index');
    }
}
