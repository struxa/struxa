<?php

declare(strict_types=1);

namespace StripeStorePlugin;

final class StripeBootstrap
{
    /**
     * Ensures Stripe\Stripe is autoloadable: root composer (metapackage) or plugin-local vendor after `composer install` in the plugin dir.
     */
    public static function load(string $pluginRoot): void
    {
        if (class_exists(\Stripe\Stripe::class, true)) {
            return;
        }

        $pluginAutoload = rtrim($pluginRoot, '/\\') . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
        if (is_file($pluginAutoload)) {
            require_once $pluginAutoload;
        }

        if (!class_exists(\Stripe\Stripe::class, true)) {
            throw new \RuntimeException(
                'Stripe PHP SDK missing. At project root run: composer install (stripe/stripe-php is listed in the root composer.json), '
                . 'or from the plugin directory run: composer install. See docs/plugins.md#plugin-composer-dependencies.'
            );
        }
    }
}
