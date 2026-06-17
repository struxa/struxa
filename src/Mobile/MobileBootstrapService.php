<?php

declare(strict_types=1);

namespace App\Mobile;

use App\CmsVersion;
use App\Commerce\CommerceSettings;
use App\Content\ContentType;
use App\Content\ContentTypeRepository;
use App\Filter\FilterHook;
use App\Filter\Filters;
use App\Media\SiteBrandingResolver;
use App\Menu\MenuPublicLoader;
use App\Search\SearchSettings;
use App\Settings;
use App\Settings\SiteSettingsService;
use App\Settings\SiteUrlResolver;
use App\Theme\ThemeManager;
use App\Theme\ThemeSettingsResolver;
use PDO;

/**
 * Builds the public mobile bootstrap payload (site branding, features, nav, content types).
 */
final class MobileBootstrapService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly ThemeManager $themeManager,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(): array
    {
        $siteUrl = SiteUrlResolver::resolve();
        $settings = SiteBrandingResolver::apply(
            $this->pdo,
            (new SiteSettingsService(new \App\Settings\SettingsRepository($this->pdo)))->forTwig()
        );

        $themeSlug = $this->themeManager->activeSlug();
        $manifest = $this->themeManager->findBySlug($themeSlug);
        $themeValues = $manifest !== null
            ? (new ThemeSettingsResolver())->resolvedValues($manifest)
            : [];
        $accent = trim($themeValues['accent'] ?? '#8b7cf6');
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $accent)) {
            $accent = '#8b7cf6';
        }

        $commerce = new CommerceSettings($this->pdo);
        $commerceEnabled = $commerce->isEnabled();
        $searchEnabled = SearchSettings::enabled();
        $allPublicTypes = (new ContentTypeRepository($this->pdo))->allWithPublicRoute();
        $contentTypes = self::filterContentTypesForMobile($allPublicTypes);
        $appFeatures = MobileSettings::resolvedFeatures($commerceEnabled, $searchEnabled);
        $commerceEnabledForApp = $commerceEnabled && $appFeatures['shop'];
        $searchEnabledForApp = $searchEnabled && $appFeatures['search'];

        $tabs = MobileSettings::tabsOverride();
        if ($tabs === []) {
            $tabs = MobileSettings::defaultTabs(
                $commerceEnabledForApp,
                $searchEnabledForApp,
                count($contentTypes),
                $appFeatures,
            );
        }

        $welcomeTitle = MobileSettings::welcomeTitle();
        $welcomeMessage = MobileSettings::welcomeMessage();
        if ($welcomeTitle === '') {
            $welcomeTitle = trim($settings['site_name'] ?? 'Your Studio');
        }
        if ($welcomeMessage === '') {
            $welcomeMessage = trim($settings['site_tagline'] ?? '');
        }

        $menuLoader = new MenuPublicLoader($this->pdo);
        $navigation = [
            'header' => $this->mobileMenuItems($menuLoader->forLocation('header'), $siteUrl),
        ];
        if (MobileSettings::includeFooterNav()) {
            $navigation['footer'] = $this->mobileMenuItems($menuLoader->forLocation('footer'), $siteUrl);
        }

        $googleSso = ((string) ($settings['google_sso_enabled'] ?? '0')) === '1'
            && trim($settings['google_oauth_client_id'] ?? '') !== '';

        $firebaseSso = ((string) ($settings['firebase_enabled'] ?? '0')) === '1'
            && trim($settings['firebase_api_key'] ?? '') !== ''
            && trim($settings['firebase_auth_domain'] ?? '') !== ''
            && trim($settings['firebase_project_id'] ?? '') !== ''
            && trim($settings['firebase_app_id'] ?? '') !== '';

        $firebaseClient = null;
        if ($firebaseSso) {
            $firebaseClient = [
                'apiKey' => trim($settings['firebase_api_key'] ?? ''),
                'authDomain' => trim($settings['firebase_auth_domain'] ?? ''),
                'projectId' => trim($settings['firebase_project_id'] ?? ''),
                'appId' => trim($settings['firebase_app_id'] ?? ''),
            ];
            $bucket = trim($settings['firebase_storage_bucket'] ?? '');
            if ($bucket !== '') {
                $firebaseClient['storageBucket'] = $bucket;
            }
            $sender = trim($settings['firebase_messaging_sender_id'] ?? '');
            if ($sender !== '') {
                $firebaseClient['messagingSenderId'] = $sender;
            }
        }

        $mobileAuthReady = MobileRefreshTokenRepository::tableExists($this->pdo);

        $payload = [
            'schema_version' => MobileSettings::SCHEMA_VERSION,
            'cms_version' => CmsVersion::CURRENT,
            'site' => [
                'name' => trim($settings['site_name'] ?? 'Your Studio'),
                'tagline' => trim($settings['site_tagline'] ?? ''),
                'url' => $siteUrl,
                'language' => trim($settings['site_language'] ?? 'en') ?: 'en',
            ],
            'branding' => [
                'logo_url' => self::absoluteUrl($siteUrl, $settings['logo_href'] ?? ''),
                'favicon_url' => self::absoluteUrl($siteUrl, $settings['favicon_href'] ?? '/favicon.svg'),
                'accent_color' => $accent,
                'theme_slug' => $themeSlug,
            ],
            'features' => [
                'commerce' => $commerceEnabledForApp,
                'search' => $searchEnabledForApp,
                'comments' => true,
                'mobile_auth' => true,
                'mobile_auth_ready' => $mobileAuthReady,
                'browse' => $appFeatures['browse'] && count($contentTypes) > 0,
                'auth' => [
                    'login_path' => '/login',
                    'register_path' => '/register',
                    'google_sso' => $googleSso,
                    'firebase_sso' => $firebaseSso,
                    'firebase_client' => $firebaseClient,
                    'collect_username' => ((string) ($settings['registration_collect_username'] ?? '0')) === '1',
                ],
            ],
            'api' => [
                'rest_base' => $siteUrl . '/api/v1',
                'graphql' => $siteUrl . '/api/v1/graphql',
                'bootstrap' => $siteUrl . '/api/v1/mobile/bootstrap',
                'content_base' => $siteUrl . '/api/v1/mobile/content',
                'auth_login' => $siteUrl . '/api/v1/mobile/auth/login',
                'auth_register' => $siteUrl . '/api/v1/mobile/auth/register',
                'auth_refresh' => $siteUrl . '/api/v1/mobile/auth/refresh',
                'auth_logout' => $siteUrl . '/api/v1/mobile/auth/logout',
                'auth_me' => $siteUrl . '/api/v1/mobile/auth/me',
                'commerce_products' => $siteUrl . '/api/v1/mobile/commerce/products',
                'commerce_checkout' => $siteUrl . '/api/v1/mobile/commerce/checkout',
                'commerce_orders' => $siteUrl . '/api/v1/mobile/commerce/orders',
                'commerce_downloads' => $siteUrl . '/api/v1/mobile/commerce/downloads',
            ],
            'mobile' => [
                'welcome_title' => $welcomeTitle,
                'welcome_message' => $welcomeMessage,
                'tabs' => $tabs,
                'add_site_deeplink' => MobileSiteLink::deepLinkAddSite($siteUrl),
                'add_site_web_url' => MobileSiteLink::webAddSitePath($siteUrl),
            ],
            'navigation' => $navigation,
            'content_types' => array_map(
                static fn (ContentType $t): array => [
                    'slug' => $t->slug,
                    'name' => $t->name,
                    'description' => $t->description ?? '',
                    'route' => '/' . rawurlencode($t->slug),
                    'supports_featured_image' => $t->supportsFeaturedImage,
                ],
                $contentTypes
            ),
        ];

        if ($commerceEnabledForApp) {
            $payload['commerce'] = [
                'currency' => $commerce->defaultCurrency(),
                'shop_title' => trim(Settings::get(CommerceSettings::SETTING_SHOP_TITLE, '') ?: '') ?: trim($settings['site_name'] ?? 'Shop'),
                'shop_path' => '/shop',
                'product_type_slug' => $commerce->productTypeSlug(),
                'needs_checkout_country' => $commerce->needsCheckoutCountry(),
            ];
        }

        /** @var array<string, mixed> $payload */
        $payload = Filters::apply(FilterHook::MOBILE_BOOTSTRAP, $payload, [
            'site_url' => $siteUrl,
        ]);

        return $payload;
    }

    /**
     * Minimal discovery document for /.well-known/struxa.json.
     *
     * @return array<string, mixed>
     */
    public function wellKnown(): array
    {
        $siteUrl = SiteUrlResolver::resolve();

        return [
            'struxa' => true,
            'cms_version' => CmsVersion::CURRENT,
            'bootstrap_url' => $siteUrl . '/api/v1/mobile/bootstrap',
            'schema_version' => MobileSettings::SCHEMA_VERSION,
        ];
    }

    public static function absoluteUrl(string $siteUrl, string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $path) === 1) {
            return $path;
        }

        return rtrim($siteUrl, '/') . '/' . ltrim($path, '/');
    }

    /**
     * @param list<array{label: string, href: string, target: string, css_class: string}> $items
     * @return list<array{label: string, href: string, target: string}>
     */
    private function mobileMenuItems(array $items, string $siteUrl): array
    {
        $out = [];
        foreach ($items as $item) {
            $href = trim($item['href'] ?? '');
            if ($href === '' || $href === '#') {
                continue;
            }
            $out[] = [
                'label' => trim($item['label'] ?? ''),
                'href' => self::absoluteUrl($siteUrl, $href),
                'target' => trim($item['target'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * @param list<ContentType> $types
     * @return list<ContentType>
     */
    private static function filterContentTypesForMobile(array $types): array
    {
        $allowed = MobileSettings::allowedContentTypeSlugs();
        if ($allowed === []) {
            return $types;
        }
        $allowedSet = array_flip($allowed);
        $filtered = [];
        foreach ($types as $type) {
            if (isset($allowedSet[$type->slug])) {
                $filtered[] = $type;
            }
        }

        return $filtered;
    }
}
