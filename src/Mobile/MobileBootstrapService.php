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
        $contentTypes = (new ContentTypeRepository($this->pdo))->allWithPublicRoute();

        $tabs = MobileSettings::tabsOverride();
        if ($tabs === []) {
            $tabs = MobileSettings::defaultTabs($commerceEnabled, $searchEnabled, count($contentTypes));
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
                'commerce' => $commerceEnabled,
                'search' => $searchEnabled,
                'comments' => true,
                'mobile_auth' => true,
                'auth' => [
                    'login_path' => '/login',
                    'register_path' => '/register',
                    'google_sso' => $googleSso,
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
            ],
            'mobile' => [
                'welcome_title' => $welcomeTitle,
                'welcome_message' => $welcomeMessage,
                'tabs' => $tabs,
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

        if ($commerceEnabled) {
            $payload['commerce'] = [
                'currency' => $commerce->defaultCurrency(),
                'shop_title' => trim(Settings::get(CommerceSettings::SETTING_SHOP_TITLE, '') ?: '') ?: trim($settings['site_name'] ?? 'Shop'),
                'shop_path' => '/shop',
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
}
