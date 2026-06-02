<?php

declare(strict_types=1);

namespace StruxaAdmin;

use App\Content\ReservedContentSlugs;
use App\Plugin\PluginBootContext;
use App\Plugin\PluginServiceProviderInterface;
use App\Settings\SiteUrlResolver;

final class PluginServiceProvider implements PluginServiceProviderInterface
{
    public function boot(PluginBootContext $context): void
    {
        $context->registerPluginReservedSlugs(['plugins', 'themes', 'struxa-catalog']);

        $context->registerAdminNavItem('Catalog submissions', 'admin.struxa_catalog.submissions');
        $context->registerAdminNavItem('Catalog settings', 'admin.struxa_catalog.settings');

        $this->ensureScreenshotBaseUrl($context);
    }

    private function ensureScreenshotBaseUrl(PluginBootContext $context): void
    {
        $key = CatalogSettings::KEY_SCREENSHOT_BASE_URL;
        $current = trim((string) (\App\Settings::get($key, '') ?? ''));
        if ($current !== '') {
            return;
        }
        $site = SiteUrlResolver::resolve($context->pdo(), $context->projectRoot());
        if ($site === '') {
            return;
        }
        (new CatalogSettings($context->pdo(), $context->projectRoot()))->save([
            $key => rtrim($site, '/'),
        ]);
    }
}
