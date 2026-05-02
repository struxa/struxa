<?php

declare(strict_types=1);

use App\Access\PermissionSlug;
use App\Event\Events;
use App\Event\StorefrontCachesInvalidateEvent;
use App\Flash;
use App\Http\AdminThemeScreenshotHandler;
use App\Http\Middleware\RequireCmsStaff;
use App\Http\Middleware\RequirePermission;
use App\Settings;
use App\Settings\SettingsRepository;
use App\Theme\ThemeCatalogLoader;
use App\Theme\ThemeManifest;
use App\Theme\ThemeManager;
use App\Theme\ThemeRemoteInstaller;
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
    $permThemes = new RequirePermission($pdo, [PermissionSlug::MANAGE_THEMES]);
    $root = dirname(__DIR__);
    $themes = new ThemeManager($root);
    $settingsRepo = new SettingsRepository($pdo);
    $screenshot = new AdminThemeScreenshotHandler($themes);
    $catalogLoader = new ThemeCatalogLoader($root);
    $remoteInstaller = new ThemeRemoteInstaller($themes);

    $adminContext = static fn (): array => array_merge($viewData(), []);
    $withCmsUser = static function (Request $request, array $data): array {
        /** @var array<string, mixed> $cmsUser */
        $cmsUser = $request->getAttribute('cms_user') ?? [];

        return array_merge($data, ['cms_user' => $cmsUser]);
    };

    $app->group('/admin', function (\Slim\Routing\RouteCollectorProxy $group) use (
        $twig,
        $adminContext,
        $withCmsUser,
        $themes,
        $settingsRepo,
        $pdo,
        $screenshot,
        $catalogLoader,
        $remoteInstaller
    ): void {
        $group->get('/themes/screenshot/{slug}', $screenshot)->setName('admin.themes.screenshot');

        $group->get('/themes', function (Request $request, Response $response) use ($twig, $adminContext, $withCmsUser, $themes): Response {
            $active = $themes->activeSlug();

            return $twig->render($response, 'admin/themes/index.twig', $withCmsUser($request, array_merge($adminContext(), [
                'admin_nav' => 'themes',
                'installed_themes' => $themes->discover(),
                'active_theme_slug' => $active,
            ])));
        })->setName('admin.themes.index');

        $group->get('/themes/browse', function (Request $request, Response $response) use ($twig, $adminContext, $withCmsUser, $themes, $catalogLoader): Response {
            $loaded = $catalogLoader->load();
            $installed = [];
            foreach ($themes->discover() as $m) {
                $installed[$m->slug] = true;
            }

            return $twig->render($response, 'admin/themes/browse.twig', $withCmsUser($request, array_merge($adminContext(), [
                'admin_nav' => 'themes',
                'catalog_ok' => $loaded['ok'],
                'catalog_error' => $loaded['ok'] ? null : $loaded['error'],
                'catalog_themes' => $loaded['ok'] ? $loaded['entries'] : [],
                'installed_theme_slugs' => $installed,
            ])));
        })->setName('admin.themes.browse');

        $group->post('/themes/install-from-catalog', function (Request $request, Response $response) use ($themes, $catalogLoader, $remoteInstaller): Response {
            $parser = RouteContext::fromRequest($request)->getRouteParser();
            $backBrowse = $parser->urlFor('admin.themes.browse');
            $body = $request->getParsedBody();
            $body = is_array($body) ? $body : [];
            $slug = strtolower(trim((string) ($body['theme_slug'] ?? '')));
            $loaded = $catalogLoader->load();
            if (!$loaded['ok']) {
                Flash::set('error', 'Theme catalog is unavailable: ' . $loaded['error']);

                return $response->withHeader('Location', $backBrowse)->withStatus(302);
            }
            $err = $remoteInstaller->installFromCatalogSlug($slug, $loaded['entries']);
            if ($err !== null) {
                Flash::set('error', $err);

                return $response->withHeader('Location', $backBrowse)->withStatus(302);
            }
            Flash::set('success', 'Theme installed. You can activate it from the themes list.');
            Events::dispatch(new StorefrontCachesInvalidateEvent('theme_installed_from_catalog'));

            return $response
                ->withHeader('Location', $parser->urlFor('admin.themes.index'))
                ->withStatus(302);
        })->setName('admin.themes.install_from_catalog');

        $group->post('/themes/activate', function (Request $request, Response $response) use ($themes, $settingsRepo, $pdo): Response {
            $body = $request->getParsedBody();
            $body = is_array($body) ? $body : [];
            $slug = strtolower(trim((string) ($body['theme_slug'] ?? '')));
            if ($themes->findBySlug($slug) === null) {
                Flash::set('error', 'That theme is not installed or its theme.json is invalid.');
            } else {
                $settingsRepo->upsert('active_theme', $slug, true);
                Settings::reload($pdo);
                Flash::set('success', 'Theme activated.');
                Events::dispatch(new StorefrontCachesInvalidateEvent('theme_activated'));
            }

            return $response
                ->withHeader('Location', RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.themes.index'))
                ->withStatus(302);
        })->setName('admin.themes.activate');

        $group->post('/themes/remove', function (Request $request, Response $response) use ($themes, $pdo): Response {
            $body = $request->getParsedBody();
            $body = is_array($body) ? $body : [];
            $slug = strtolower(trim((string) ($body['theme_slug'] ?? '')));
            $parser = RouteContext::fromRequest($request)->getRouteParser();
            $back = $parser->urlFor('admin.themes.index');
            if ($slug === '' || !ThemeManifest::isValidSlug($slug)) {
                Flash::set('error', 'Invalid theme.');

                return $response->withHeader('Location', $back)->withStatus(302);
            }
            $err = $themes->removeInstalledTheme($slug);
            if ($err !== null) {
                Flash::set('error', $err);
            } else {
                Settings::reload($pdo);
                Flash::set('success', 'Theme removed from the server.');
                Events::dispatch(new StorefrontCachesInvalidateEvent('theme_removed'));
            }

            return $response->withHeader('Location', $back)->withStatus(302);
        })->setName('admin.themes.remove');
    })->add($permThemes)->add($middleware);
};
