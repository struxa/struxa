<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Cache\CacheConfig;
use App\Cache\CacheManager;
use App\CmsVersion;
use App\Media\SiteBrandingResolver;
use App\Menu\MenuPublicLoader;
use App\Settings\SettingsRepository;
use App\Settings\SiteSettingsService;
use App\Plugin\PluginAdminNavRegistry;
use App\Theme\ThemeManager;
use App\Theme\ThemeSettingsResolver;
use App\Update\CmsUpdateChecker;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Views\Twig;

final class TwigCmsGlobals implements MiddlewareInterface
{
    public function __construct(
        private readonly Twig $twig,
        private readonly PDO $pdo,
        private readonly ThemeManager $themeManager,
        private readonly ?CacheManager $cacheManager = null,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $env = $this->twig->getEnvironment();
        $ttl = CacheConfig::internalTtlSeconds();
        $internal = $this->cacheManager?->internal();

        if ($internal !== null) {
            /** @var array<string, string>|null $settings */
            $settings = $internal->get('twig_globals:settings');
            if (!is_array($settings)) {
                $settingsSvc = new SiteSettingsService(new SettingsRepository($this->pdo));
                $settings = SiteBrandingResolver::apply($this->pdo, $settingsSvc->forTwig());
                $internal->set('twig_globals:settings', $settings, $ttl);
            }
        } else {
            $settingsSvc = new SiteSettingsService(new SettingsRepository($this->pdo));
            $settings = SiteBrandingResolver::apply($this->pdo, $settingsSvc->forTwig());
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
        $env->addGlobal('footer_menu', $footer);

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
        $env->addGlobal('site_url', rtrim($_ENV['PHPAUTH_SITE_URL'] ?? 'http://localhost:8080', '/'));
        $uri = $request->getUri();
        $requestPath = $this->normalizeRequestPath($uri->getPath());
        $env->addGlobal('request_path', $requestPath);
        $headerForTwig = [];
        foreach ($header as $item) {
            $headerForTwig[] = array_merge($item, [
                'is_active' => $this->navHrefMatchesRequest($item['href'], $requestPath),
            ]);
        }
        $env->addGlobal('header_menu', $headerForTwig);
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
        $env->addGlobal('plugin_admin_nav_items', PluginAdminNavRegistry::instance()->all());
        $env->addGlobal('cms_public_page_cache_on', CacheConfig::publicCacheEnabled());
        $env->addGlobal('cms_version', CmsVersion::CURRENT);

        $adminPath = $this->normalizeRequestPath($request->getUri()->getPath());
        if (str_starts_with($adminPath, '/admin') && $internal !== null) {
            $env->addGlobal('cms_update_status', (new CmsUpdateChecker($internal))->check());
        } else {
            $env->addGlobal('cms_update_status', [
                'ok' => true,
                'skipped' => true,
                'update_available' => false,
                'current_version' => CmsVersion::CURRENT,
                'fetched_at' => time(),
                'feed_url' => '',
            ]);
        }

        return $handler->handle($request);
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
}
