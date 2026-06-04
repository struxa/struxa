<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\NamedRouteUrl;
use App\Cache\CacheConfig;
use App\Cache\CacheManager;
use App\Commerce\Cart\CartService;
use App\Commerce\CommerceSettings;
use App\Locale\SiteLocale;
use App\CmsVersion;
use App\Media\SiteBrandingResolver;
use App\Menu\MenuPublicLoader;
use App\Seo\ExternalLinkPolicy;
use App\Settings\SettingsRepository;
use App\Settings\SiteSettingsService;
use App\Settings\SiteUrlResolver;
use App\Plugin\PluginAdminNavGrouper;
use App\Plugin\PluginAdminNavRegistry;
use App\Plugin\PluginScanner;
use App\Theme\ThemeManager;
use App\Theme\ThemeSettingsResolver;
use App\News\StruxaNewsFeed;
use App\Search\ContentSearchService;
use App\Search\SearchSettings;
use App\Update\CmsUpdateChecker;
use InvalidArgumentException;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;
use Slim\Interfaces\RouteParserInterface;
use Slim\Views\Twig;
use Twig\TwigFunction;

final class TwigCmsGlobals implements MiddlewareInterface
{
    private static bool $routeUrlFunctionRegistered = false;

    public function __construct(
        private readonly Twig $twig,
        private readonly PDO $pdo,
        private readonly ThemeManager $themeManager,
        private readonly ?CacheManager $cacheManager = null,
        private readonly ?RouteParserInterface $routeParser = null,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $env = $this->twig->getEnvironment();
        if (!self::$routeUrlFunctionRegistered && $this->routeParser !== null) {
            $parser = $this->routeParser;
            $env->addFunction(new TwigFunction('cms_route_url', static function (string $name, array $params = []) use ($parser): ?string {
                $stringParams = [];
                foreach ($params as $k => $v) {
                    if (is_string($k) && is_string($v)) {
                        $stringParams[$k] = $v;
                    }
                }

                return NamedRouteUrl::tryFor($parser, $name, $stringParams);
            }));
            self::$routeUrlFunctionRegistered = true;
        }
        $ttl = CacheConfig::internalTtlSeconds();
        $internal = $this->cacheManager?->internal();

        if ($internal !== null) {
            /** @var array<string, string>|null $settings */
            $settings = $internal->get('twig_globals:settings');
            if (!is_array($settings)) {
                $settingsSvc = new SiteSettingsService(new SettingsRepository($this->pdo));
                $settings = self::applySiteLocaleToSettings(SiteBrandingResolver::apply($this->pdo, $settingsSvc->forTwig()));
                $internal->set('twig_globals:settings', $settings, $ttl);
            } else {
                $settings = self::applySiteLocaleToSettings($settings);
            }
        } else {
            $settingsSvc = new SiteSettingsService(new SettingsRepository($this->pdo));
            $settings = self::applySiteLocaleToSettings(SiteBrandingResolver::apply($this->pdo, $settingsSvc->forTwig()));
        }
        $env->addGlobal('settings', $settings);

        if ($internal !== null) {
            $header = $internal->get('twig_globals:menu:header');
            $footer = $internal->get('twig_globals:menu:footer');
            if (!is_array($header)) {
                $menus = new MenuPublicLoader($this->pdo);
                $header = $menus->forLocation('header');
                $internal->set('twig_globals:menu:header', $header, $ttl);
            }
            if (!is_array($footer)) {
                $menus = new MenuPublicLoader($this->pdo);
                $footer = $menus->forLocation('footer');
                $internal->set('twig_globals:menu:footer', $footer, $ttl);
            }
        } else {
            $menus = new MenuPublicLoader($this->pdo);
            $header = $menus->forLocation('header');
            $footer = $menus->forLocation('footer');
        }

        $activeSlug = $this->themeManager->activeSlug();
        if ($internal !== null) {
            /** @var array{m: array<string, mixed>, s: array<string, string>}|null $bundle */
            $bundle = $internal->get('twig_globals:theme:' . $activeSlug);
            if (is_array($bundle) && isset($bundle['m'], $bundle['s']) && is_array($bundle['m']) && is_array($bundle['s'])) {
                $manifestArr = $bundle['m'];
                $themeSettings = $bundle['s'];
            } else {
                $manifest = $this->themeManager->findBySlug($activeSlug);
                $manifestArr = $manifest !== null ? $manifest->toArray() : [];
                $themeSettings = $manifest !== null ? (new ThemeSettingsResolver())->resolvedValues($manifest) : [];
                $internal->set('twig_globals:theme:' . $activeSlug, ['m' => $manifestArr, 's' => $themeSettings], $ttl);
            }
        } else {
            $manifest = $this->themeManager->findBySlug($activeSlug);
            $manifestArr = $manifest !== null ? $manifest->toArray() : [];
            $themeSettings = $manifest !== null ? (new ThemeSettingsResolver())->resolvedValues($manifest) : [];
        }
        $siteUrlGlobal = SiteUrlResolver::resolve();
        $env->addGlobal('site_url', $siteUrlGlobal);
        $navRelHost = ExternalLinkPolicy::siteHostFromSiteUrl($siteUrlGlobal);
        $navNofollowExternal = ExternalLinkPolicy::isEnabled();
        $uri = $request->getUri();
        $requestPath = $this->normalizeRequestPath($uri->getPath());
        $env->addGlobal('request_path', $requestPath);
        $headerForTwig = [];
        foreach ($header as $item) {
            $headerForTwig[] = array_merge($item, [
                'is_active' => $this->navHrefMatchesRequest($item['href'], $requestPath),
                'anchor_rel' => ExternalLinkPolicy::anchorRelForNavLink(
                    (string) ($item['href'] ?? ''),
                    (string) ($item['target'] ?? ''),
                    $navNofollowExternal,
                    $navRelHost
                ),
            ]);
        }
        $env->addGlobal('header_menu', $headerForTwig);
        $footerForTwig = [];
        foreach ($footer as $item) {
            $footerForTwig[] = array_merge($item, [
                'anchor_rel' => ExternalLinkPolicy::anchorRelForNavLink(
                    (string) ($item['href'] ?? ''),
                    (string) ($item['target'] ?? ''),
                    $navNofollowExternal,
                    $navRelHost
                ),
            ]);
        }
        $env->addGlobal('footer_menu', $footerForTwig);
        $ro = $uri->getScheme() . '://' . $uri->getHost();
        $port = $uri->getPort();
        if ($port !== null) {
            $def = $uri->getScheme() === 'https' ? 443 : 80;
            if ($port !== $def) {
                $ro .= ':' . $port;
            }
        }
        $env->addGlobal('request_origin', rtrim($ro, '/'));
        $env->addGlobal('active_theme', $activeSlug);
        $env->addGlobal('active_theme_manifest', $manifestArr);
        $env->addGlobal('theme_settings', $themeSettings);
        $pluginNav = PluginAdminNavRegistry::instance()->all();
        if ($this->routeParser !== null) {
            $pluginNav = $this->filterPluginAdminNavForResolvableRoutes($pluginNav);
        }
        $projectRoot = dirname(__DIR__, 3);
        $partition = PluginAdminNavGrouper::partition($pluginNav, new PluginScanner($projectRoot));
        $env->addGlobal('plugin_admin_nav_items', $partition['flat']);
        $env->addGlobal('plugin_admin_nav_groups', $partition['groups']);
        $env->addGlobal('struxa_catalog_nav', self::struxaCatalogNav($projectRoot, $this->routeParser));
        $env->addGlobal('cms_public_page_cache_on', CacheConfig::publicCacheEnabled());
        $env->addGlobal('cms_version', CmsVersion::CURRENT);

        $commerceSettings = new CommerceSettings($this->pdo);
        $env->addGlobal('commerce_enabled', $commerceSettings->isEnabled());
        $cartCount = 0;
        if ($commerceSettings->isEnabled()) {
            if (session_status() !== PHP_SESSION_ACTIVE) {
                @session_start();
            }
            if (session_status() === PHP_SESSION_ACTIVE) {
                $cartCount = (new CartService())->count();
            }
        }
        $env->addGlobal('commerce_cart_count', $cartCount);

        $storefrontSearchOn = SearchSettings::enabled();
        $env->addGlobal('storefront_search_enabled', $storefrontSearchOn);
        $env->addGlobal('search_query_min_length', ContentSearchService::MIN_QUERY_LENGTH);
        $env->addGlobal('search_query_max_length', ContentSearchService::MAX_QUERY_LENGTH);

        $adminPath = $this->normalizeRequestPath($request->getUri()->getPath());
        $internalForUpdates = $internal ?? (new CacheManager(
            dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'cache'
        ))->internal();
        if ($this->isAdminRequestPath($adminPath)) {
            $env->addGlobal('cms_update_status', (new CmsUpdateChecker($internalForUpdates, $this->pdo))->checkForAdminUi());
            $env->addGlobal('struxa_news', (new StruxaNewsFeed($internalForUpdates))->fetchForAdminUi());
        } else {
            $env->addGlobal('cms_update_status', [
                'ok' => true,
                'skipped' => true,
                'update_available' => false,
                'current_version' => CmsVersion::CURRENT,
                'fetched_at' => time(),
                'feed_url' => '',
            ]);
            $env->addGlobal('struxa_news', [
                'ok' => true,
                'skipped' => true,
                'items' => [],
                'feed_url' => '',
                'fetched_at' => time(),
            ]);
        }

        return $handler->handle($request);
    }

    /**
     * Drop plugin sidebar links whose named route is missing or cannot be built (avoids 500s from url_for).
     *
     * @param array<int, mixed> $items
     *
     * @return array<int, array<string, mixed>>
     */
    private function filterPluginAdminNavForResolvableRoutes(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $name = $item['route_name'] ?? '';
            if (!is_string($name) || $name === '') {
                continue;
            }
            $params = $item['route_params'] ?? [];
            if (!is_array($params)) {
                $params = [];
            }
            $stringParams = [];
            foreach ($params as $k => $v) {
                if (is_string($k) && is_string($v)) {
                    $stringParams[$k] = $v;
                }
            }
            try {
                $this->routeParser->urlFor($name, $stringParams);
            } catch (RuntimeException | InvalidArgumentException) {
                continue;
            }
            $out[] = $item;
        }

        return $out;
    }

    /**
     * @param array<string, string> $settings
     * @return array<string, string>
     */
    private static function applySiteLocaleToSettings(array $settings): array
    {
        $code = SiteLocale::normalizeSetting($settings['site_language'] ?? 'en');
        $settings['site_language'] = $code;
        $html = SiteLocale::htmlLang($code);
        $settings['site_html_lang'] = $html;
        $settings['site_og_locale'] = SiteLocale::ogLocale($html);

        return $settings;
    }

    private function normalizeRequestPath(string $path): string
    {
        $path = $path === '' ? '/' : $path;
        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }

        return $path === '' ? '/' : $path;
    }

    /**
     * True when the request targets the CMS admin UI (root /admin or subdirectory .../admin/...).
     */
    private function isAdminRequestPath(string $path): bool
    {
        return preg_match('#(?:^|/)admin(?:/|$)#', $path) === 1;
    }

    /**
     * @return non-empty-string|null
     */
    private function normalizeHrefPath(string $href): ?string
    {
        $href = trim($href);
        if ($href === '' || $href === '#') {
            return null;
        }
        if (preg_match('#^https?://#i', $href) === 1) {
            $parts = parse_url($href);
            if (!is_array($parts)) {
                return null;
            }
            $path = isset($parts['path']) && is_string($parts['path']) ? $parts['path'] : '/';
        } else {
            $path = $href;
        }
        if ($path === '') {
            $path = '/';
        }
        if ($path[0] !== '/') {
            $path = '/' . $path;
        }
        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }

        return $path === '' ? '/' : $path;
    }

    private function navHrefMatchesRequest(string $href, string $requestPath): bool
    {
        $menuPath = $this->normalizeHrefPath($href);
        if ($menuPath === null) {
            return false;
        }
        if ($menuPath === $requestPath) {
            return true;
        }
        if ($menuPath !== '/' && str_starts_with($requestPath, $menuPath . '/')) {
            return true;
        }

        return false;
    }

    /**
     * Sidebar URLs for Struxa Catalog Admin (plugin on disk + named routes or path fallback from Plugins URL).
     *
     * @return array{show: bool, submissions_url: ?string, settings_url: ?string, routes_registered: bool}
     */
    private static function struxaCatalogNav(string $projectRoot, ?RouteParserInterface $parser): array
    {
        $scanner = new PluginScanner($projectRoot);
        if ($scanner->findBySlug('struxa-admin') === null) {
            return ['show' => false, 'submissions_url' => null, 'settings_url' => null, 'routes_registered' => false];
        }

        $submissions = NamedRouteUrl::tryFor($parser, 'admin.struxa_catalog.submissions');
        $settings = NamedRouteUrl::tryFor($parser, 'admin.struxa_catalog.settings');
        $routesRegistered = $submissions !== null && $settings !== null;

        return [
            'show' => $routesRegistered,
            'submissions_url' => $submissions,
            'settings_url' => $settings,
            'routes_registered' => $routesRegistered,
        ];
    }
}
