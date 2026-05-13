<?php

declare(strict_types=1);

namespace AviosDestinationReviewPlugin;

use App\Plugin\PluginBootContext;
use App\Plugin\PluginServiceProviderInterface;
use Twig\Environment as TwigEnvironment;

final class AviosDestinationReviewServiceProvider implements PluginServiceProviderInterface
{
    public function boot(PluginBootContext $context): void
    {
        // Top-level admin nav links under "Extensions". The list page itself handles the
        // dependency banner when How Many Avios isn't active — routes always register so
        // admins land on a useful page either way.
        $context->registerAdminNavItem('Avios Destination Review', 'plugin.avios_destination_review.list');
        $context->registerAdminNavItem('Avios Destination Review · Settings', 'plugin.avios_destination_review.settings');

        // Expose the homepage "Best Avios Redemptions" data source. Themes can call
        // `adr_best_redemptions(5)` on demand or read the eager-loaded global below.
        $env = $context->twig()->getEnvironment();
        if (!$env instanceof TwigEnvironment) {
            return;
        }

        if (!$env->hasExtension(AviosDestinationReviewTwigExtension::class)) {
            $env->addExtension(new AviosDestinationReviewTwigExtension($context->pdo()));
        }

        // Eager-loaded top-5 redemptions as a Twig global so themes can branch with
        // `{% if adr_best_redemptions_top %}` without invoking the function, keeping the
        // page safe to render even when the plugin is later disabled (variable becomes
        // undefined and the home template's `|default([])` fallback covers it).
        $env->addGlobal(
            'adr_best_redemptions_top',
            (new AviosDestinationReviewTwigExtension($context->pdo()))->bestRedemptions(5)
        );
    }
}
