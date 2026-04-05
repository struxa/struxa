<?php

declare(strict_types=1);

use App\Access\PermissionSlug;
use App\Cache\CacheConfig;
use App\Cache\CacheFileInspector;
use App\Cache\CacheManager;
use App\Cache\StorefrontCacheInvalidator;
use App\Theme\ThemeManager;
use App\Flash;
use App\Http\Middleware\RequireCmsStaff;
use App\Http\Middleware\RequirePermission;
use App\Settings;
use App\Settings\SettingsRepository;
use PHPAuth\Auth;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Slim\Routing\RouteContext;
use Slim\Views\Twig;

/**
 * @param callable(): array<string, mixed> $viewData
 */
return static function (App $app, Twig $twig, Auth $auth, \PDO $pdo, callable $viewData): void {
    $middleware = new RequireCmsStaff($auth, $pdo);
    $perm = new RequirePermission($pdo, [PermissionSlug::MANAGE_SETTINGS]);
    $root = dirname(__DIR__);
    $cacheManager = new CacheManager($root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'cache');
    $themeManager = new ThemeManager($root);
    $invalidator = new StorefrontCacheInvalidator($cacheManager, $themeManager);

    $adminContext = static fn (): array => array_merge($viewData(), []);
    $withCmsUser = static function (Request $request, array $data): array {
        /** @var array<string, mixed> $cmsUser */
        $cmsUser = $request->getAttribute('cms_user') ?? [];

        return array_merge($data, ['cms_user' => $cmsUser]);
    };

    $countJsonFiles = static function (string $dir): array {
        if (!is_dir($dir)) {
            return ['count' => 0, 'bytes' => 0];
        }
        $count = 0;
        $bytes = 0;
        foreach (glob($dir . DIRECTORY_SEPARATOR . '*.json') ?: [] as $f) {
            if (is_file($f)) {
                ++$count;
                $bytes += (int) filesize($f);
            }
        }

        return ['count' => $count, 'bytes' => $bytes];
    };

    $app->group('/admin', function (\Slim\Routing\RouteCollectorProxy $group) use (
        $twig,
        $adminContext,
        $withCmsUser,
        $pdo,
        $cacheManager,
        $invalidator,
        $countJsonFiles
    ): void {
        $group->get('/tools/cache', function (Request $request, Response $response) use (
            $twig,
            $adminContext,
            $withCmsUser,
            $cacheManager,
            $countJsonFiles
        ): Response {
            $publicDir = $cacheManager->publicResponses()->namespacePath();
            $internalDir = $cacheManager->internal()->namespacePath();
            $publicStats = $countJsonFiles($publicDir);
            $internalStats = $countJsonFiles($internalDir);
            $publicInspect = CacheFileInspector::listPublicResponseEntries($publicDir);
            $internalInspect = CacheFileInspector::listInternalEntries($internalDir);

            return $twig->render($response, 'admin/tools/cache.twig', $withCmsUser($request, array_merge($adminContext(), [
                'admin_nav' => 'cache_tools',
                'cache_public_enabled' => CacheConfig::publicCacheEnabled(),
                'cache_public_ttl_sec' => CacheConfig::publicTtlSeconds(),
                'cache_internal_ttl_sec' => CacheConfig::internalTtlSeconds(),
                'storefront_css_minify' => Settings::get('storefront_css_minify') === '1',
                'assets_prefer_minified' => Settings::get('assets_prefer_minified') === '1',
                'cache_public_files' => $publicStats['count'],
                'cache_internal_files' => $internalStats['count'],
                'cache_public_bytes' => $publicStats['bytes'],
                'cache_internal_bytes' => $internalStats['bytes'],
                'cache_storage_path' => $cacheManager->publicResponses()->getBasePath(),
                'cache_store_driver' => CacheConfig::activePublicStoreDriverLabel(),
                'cache_max_body_mb' => round(CacheConfig::publicCacheMaxBodyBytes() / 1_000_000, 1),
                'cache_public_entries' => $publicInspect['rows'],
                'cache_public_entries_total' => $publicInspect['total_files'],
                'cache_public_entries_truncated' => $publicInspect['truncated'],
                'cache_internal_entries' => $internalInspect['rows'],
                'cache_internal_entries_total' => $internalInspect['total_files'],
                'cache_internal_entries_truncated' => $internalInspect['truncated'],
                'errors' => [],
            ])));
        })->setName('admin.tools.cache');

        $group->post('/tools/cache', function (Request $request, Response $response) use (
            $pdo,
            $invalidator,
        ): Response {
            $body = $request->getParsedBody();
            $action = is_array($body) ? trim((string) ($body['action'] ?? '')) : '';
            $routeParser = RouteContext::fromRequest($request)->getRouteParser();
            $back = $routeParser->urlFor('admin.tools.cache');
            $resolveToolbarBack = static function (Request $req) use ($routeParser): string {
                $ref = $req->getHeaderLine('Referer');
                if ($ref === '') {
                    return $routeParser->urlFor('admin.dashboard');
                }
                /** @var array{scheme?: string, host?: string, path?: string, query?: string, fragment?: string}|false $parts */
                $parts = parse_url($ref);
                if (!is_array($parts) || empty($parts['host'])) {
                    return $routeParser->urlFor('admin.dashboard');
                }
                if (strcasecmp((string) $parts['host'], $req->getUri()->getHost()) !== 0) {
                    return $routeParser->urlFor('admin.dashboard');
                }
                $path = isset($parts['path']) && is_string($parts['path']) ? $parts['path'] : '/';
                if (!str_starts_with($path, '/admin')) {
                    return $routeParser->urlFor('admin.dashboard');
                }
                $q = isset($parts['query']) && is_string($parts['query']) ? '?' . $parts['query'] : '';
                $frag = isset($parts['fragment']) && is_string($parts['fragment']) ? '#' . $parts['fragment'] : '';

                return $path . $q . $frag;
            };

            if ($action === 'toolbar_disable') {
                (new SettingsRepository($pdo))->upsert('cache_public_enabled', '0', true);
                Settings::reload($pdo);
                Flash::set('success', 'Public page cache disabled.');
                $target = $resolveToolbarBack($request);

                return $response->withHeader('Location', $target)->withStatus(302);
            }

            if ($action === 'toolbar_clear') {
                $invalidator->flushAll();
                Settings::reload($pdo);
                Flash::set('success', 'Storefront caches cleared.');
                $target = $resolveToolbarBack($request);

                return $response->withHeader('Location', $target)->withStatus(302);
            }

            if ($action === 'clear') {
                $invalidator->flushAll();
                Flash::set('success', 'Storefront caches cleared.');
                Settings::reload($pdo);

                return $response->withHeader('Location', $back)->withStatus(302);
            }

            if ($action === 'save') {
                $en = is_array($body) && !empty($body['cache_public_enabled']) ? '1' : '0';
                $ttlRaw = is_array($body) ? trim((string) ($body['cache_public_ttl_sec'] ?? '')) : '';
                $ttl = ctype_digit($ttlRaw) ? max(30, min(86400, (int) $ttlRaw)) : 300;
                $cssMin = is_array($body) && !empty($body['storefront_css_minify']) ? '1' : '0';
                $preferMin = is_array($body) && !empty($body['assets_prefer_minified']) ? '1' : '0';
                $repo = new SettingsRepository($pdo);
                $repo->upsertMany([
                    'cache_public_enabled' => $en,
                    'cache_public_ttl_sec' => (string) $ttl,
                    'storefront_css_minify' => $cssMin,
                    'assets_prefer_minified' => $preferMin,
                ], true);
                Settings::reload($pdo);
                if ($cssMin === '0') {
                    $invalidator->flushThemeCssMinDisk();
                }
                Flash::set('success', 'Performance & cache settings saved.');

                return $response->withHeader('Location', $back)->withStatus(302);
            }

            Flash::set('error', 'Unknown action.');

            return $response->withHeader('Location', $back)->withStatus(302);
        })->setName('admin.tools.cache.process');
    })->add($perm)->add($middleware);
};
