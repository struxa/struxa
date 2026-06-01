<?php

declare(strict_types=1);

use App\Access\PermissionSlug;
use App\Commerce\CommerceSettings;
use App\Content\ContentTypeRepository;
use App\Flash;
use App\Http\Middleware\RequireCmsStaff;
use App\Http\Middleware\RequirePermission;
use App\Mobile\MobileBootstrapService;
use App\Mobile\MobileSettings;
use App\Mobile\MobileSiteLink;
use App\Search\SearchSettings;
use App\Settings\SiteUrlResolver;
use PHPAuth\Auth;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Slim\Routing\RouteContext;
use Slim\Views\Twig;

/**
 * @param callable(): array<string, mixed> $viewData
 */
return static function (App $app, Twig $twig, Auth $auth, \PDO $pdo, \App\Theme\ThemeManager $themeManager, callable $viewData): void {
    $middleware = new RequireCmsStaff($auth, $pdo);
    $perm = new RequirePermission($pdo, [PermissionSlug::MANAGE_SETTINGS]);

    $adminContext = static fn (): array => array_merge($viewData(), []);
    $withCmsUser = static function (Request $request, array $data): array {
        /** @var array<string, mixed> $cmsUser */
        $cmsUser = $request->getAttribute('cms_user') ?? [];

        return array_merge($data, ['cms_user' => $cmsUser]);
    };

    $siteUrl = SiteUrlResolver::resolve();
    $bootstrapUrl = $siteUrl . '/api/v1/mobile/bootstrap';
    $wellKnownUrl = $siteUrl . '/.well-known/struxa.json';

    $renderMobileAdmin = static function (
        Request $request,
        Response $response
    ) use (
        $twig,
        $pdo,
        $themeManager,
        $adminContext,
        $withCmsUser,
        $bootstrapUrl,
        $wellKnownUrl,
        $siteUrl,
    ): Response {
        $commerce = new CommerceSettings($pdo);
        $commerceOnSite = $commerce->isEnabled();
        $searchOnSite = SearchSettings::enabled();
        $publicTypes = (new ContentTypeRepository($pdo))->allWithPublicRoute();
        $productSlug = $commerceOnSite ? $commerce->productTypeSlug() : '';

        $storedSlugs = MobileSettings::allowedContentTypeSlugs();
        $restrictContent = trim((string) (\App\Settings::get(MobileSettings::SETTING_CONTENT_SLUGS_JSON, '') ?? '')) !== '';
        $selectedSlugs = $restrictContent ? $storedSlugs : array_map(static fn ($t) => $t->slug, $publicTypes);

        $savedFeatures = MobileSettings::parseFeaturesJson();
        $featuresBrowse = (bool) ($savedFeatures['browse'] ?? true);
        $featuresSearch = (bool) ($savedFeatures['search'] ?? true);
        $featuresShop = (bool) ($savedFeatures['shop'] ?? true);
        $featuresAccount = (bool) ($savedFeatures['account'] ?? true);

        $preview = null;
        $previewJson = '';
        if (MobileSettings::enabled()) {
            try {
                $preview = (new MobileBootstrapService($pdo, $themeManager))->build();
                $previewJson = json_encode($preview, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
            } catch (\Throwable) {
                $preview = null;
                $previewJson = '';
            }
        }

        return $twig->render($response, 'admin/mobile/index.twig', $withCmsUser($request, array_merge($adminContext(), [
            'admin_nav' => 'mobile_app',
            'mobile_enabled' => MobileSettings::enabled(),
            'mobile_welcome_title' => MobileSettings::welcomeTitle(),
            'mobile_welcome_message' => MobileSettings::welcomeMessage(),
            'mobile_include_footer_nav' => MobileSettings::includeFooterNav(),
            'mobile_tabs_json' => (string) (\App\Settings::get(MobileSettings::SETTING_TABS_JSON, '') ?? ''),
            'mobile_bootstrap_url' => $bootstrapUrl,
            'mobile_well_known_url' => $wellKnownUrl,
            'mobile_deeplink' => MobileSiteLink::deepLinkAddSite($siteUrl),
            'mobile_add_web_url' => MobileSiteLink::webAddSitePath($siteUrl),
            'mobile_qr_url' => MobileSiteLink::webAddSitePath($siteUrl) . '/qr.svg',
            'mobile_preview' => $preview,
            'mobile_preview_json' => $previewJson,
            'mobile_public_types' => array_map(static fn ($t) => [
                'slug' => $t->slug,
                'name' => $t->name,
                'description' => $t->description ?? '',
                'is_product_type' => $productSlug !== '' && $t->slug === $productSlug,
            ], $publicTypes),
            'mobile_selected_content_slugs' => $selectedSlugs,
            'mobile_restrict_content' => $restrictContent,
            'mobile_commerce_on_site' => $commerceOnSite,
            'mobile_search_on_site' => $searchOnSite,
            'mobile_feature_browse' => $featuresBrowse,
            'mobile_feature_search' => $featuresSearch,
            'mobile_feature_shop' => $featuresShop,
            'mobile_feature_account' => $featuresAccount,
            'mobile_use_custom_tabs' => trim((string) (\App\Settings::get(MobileSettings::SETTING_TABS_JSON, '') ?? '')) !== '',
        ])));
    };

    $saveMobileAdmin = static function (Request $request, Response $response) use ($pdo): Response {
        $body = (array) $request->getParsedBody();
        $enabled = !empty($body['enabled']);
        $includeFooter = !empty($body['include_footer_nav']);
        $welcomeTitle = isset($body['welcome_title']) && is_string($body['welcome_title']) ? $body['welcome_title'] : '';
        $welcomeMessage = isset($body['welcome_message']) && is_string($body['welcome_message']) ? $body['welcome_message'] : '';
        $tabsJson = isset($body['tabs_json']) && is_string($body['tabs_json']) ? $body['tabs_json'] : '';
        $useCustomTabs = !empty($body['use_custom_tabs']);
        if (!$useCustomTabs) {
            $tabsJson = '';
        }

        $restrictContent = !empty($body['restrict_content_types']);
        $contentSlugs = [];
        if ($restrictContent) {
            $raw = $body['content_slugs'] ?? [];
            if (is_array($raw)) {
                foreach ($raw as $slug) {
                    if (is_string($slug) && $slug !== '') {
                        $contentSlugs[] = $slug;
                    }
                }
            }
        }

        $features = [
            'browse' => !empty($body['feature_browse']),
            'search' => !empty($body['feature_search']),
            'shop' => !empty($body['feature_shop']),
            'account' => !empty($body['feature_account']),
        ];

        MobileSettings::save(
            $pdo,
            $enabled,
            $welcomeTitle,
            $welcomeMessage,
            $includeFooter,
            $tabsJson,
            $restrictContent ? $contentSlugs : [],
            $features,
        );
        Flash::set('success', 'Mobile app settings saved.');

        $url = RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.mobile.index');

        return $response->withHeader('Location', $url)->withStatus(302);
    };

    $app->get('/admin/mobile', $renderMobileAdmin)->setName('admin.mobile.index')->add($perm)->add($middleware);
    $app->post('/admin/mobile', $saveMobileAdmin)->setName('admin.mobile.save')->add($perm)->add($middleware);

    $app->get('/admin/settings/mobile', function (Request $request, Response $response) use ($renderMobileAdmin): Response {
        return $renderMobileAdmin($request, $response);
    })->setName('admin.settings.mobile')->add($perm)->add($middleware);

    $app->post('/admin/settings/mobile', $saveMobileAdmin)->setName('admin.settings.mobile.save')->add($perm)->add($middleware);
};
