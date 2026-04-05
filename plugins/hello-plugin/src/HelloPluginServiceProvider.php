<?php

declare(strict_types=1);

namespace HelloPlugin;

use App\Event\ContentEntrySavedEvent;
use App\Plugin\PluginBootContext;
use App\Plugin\PluginServiceProviderInterface;

final class HelloPluginServiceProvider implements PluginServiceProviderInterface
{
    public function boot(PluginBootContext $context): void
    {
        $context->events()->listen(ContentEntrySavedEvent::class, static function (ContentEntrySavedEvent $e): void {
            // Example: hook for cache busting, search index, etc.
            if ($e->isNew) {
                // no-op sample
            }
        });

        $context->registerAdminNavItem('Hello (sample)', 'plugin.hello_plugin.admin');
    }
}
