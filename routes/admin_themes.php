<?php

declare(strict_types=1);

use App\Access\PermissionSlug;
use App\Dist\PackageZipUploadReader;
use App\Event\Events;
use App\Event\StorefrontCachesInvalidateEvent;
use App\Flash;
use App\Http\AdminThemeScreenshotHandler;
use App\Http\Middleware\RequireCmsStaff;
use App\Http\Middleware\RequirePermission;
use App\Settings;
use App\Settings\SettingsRepository;
use App\Cache\CacheManager;
use App\Plugin\PluginUpdateChecker;
use App\Theme\ThemeCatalogLoader;
use App\Theme\ThemeManifest;
use App\Theme\ThemeManager;
use App\Theme\ThemeRemoteInstaller;
use App\Theme\ThemeUpdateChecker;
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
    $themeUpdateChecker = new ThemeUpdateChecker(
        (new CacheManager($root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'cache'))->internal(),
        $catalogLoader,
    );

    $adminContext = static fn (): array => array_merge($viewData(), []);
    $namedRouteUrl = static function (Request $request, string $name): ?string {
        try {
            return RouteContext::fromRequest($request)->getRouteParser()->urlFor($name);
        } catch (\Throwable) {
            return null;
        }
    };
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
        $remoteInstaller,
        $themeUpdateChecker,
        $namedRouteUrl
    ): void {
        $group->get('/themes/screenshot/{slug}', $screenshot)->setName('admin.themes.screenshot');

        $group->get('/themes', function (Request $request, Response $response) use ($twig, $adminContext, $withCmsUser, $themes, $themeUpdateChecker, $namedRouteUrl): Response {
            $active = $themes->activeSlug();
            $catalogBySlug = $themeUpdateChecker->catalogEntriesBySlug();
            $rows = [];
            $updateCount = 0;
            foreach ($themes->discover() as $m) {
                $update = $themeUpdateChecker->statusFor($m, $catalogBySlug[$m->slug] ?? null);
                if ($update['update_available']) {
                    $updateCount++;
                }
                $rows[] = [
                    'theme' => $m,
                    'update' => $update,
                ];
            }

            return $twig->render($response, 'admin/themes/index.twig', $withCmsUser($request, array_merge($adminContext(), [
                'admin_nav' => 'themes',
                'theme_rows' => $rows,
                'theme_update_count' => $updateCount,
                'active_theme_slug' => $active,
                'struxa_catalog_settings_url' => $namedRouteUrl($request, 'admin.struxa_catalog.settings'),
            ])));
        })->setName('admin.themes.index');

        $group->get('/themes/browse', function (Request $request, Response $response) use ($twig, $adminContext, $withCmsUser, $themes, $catalogLoader, $themeUpdateChecker): Response {
            $loaded = $catalogLoader->load();
            $installed = [];
            $installedUpdates = [];
            foreach ($themes->discover() as $m) {
                $installed[$m->slug] = $m;
            }
            $catalogBySlug = $themeUpdateChecker->catalogEntriesBySlug();
            foreach ($installed as $slug => $m) {
                $installedUpdates[$slug] = $themeUpdateChecker->statusFor($m, $catalogBySlug[$slug] ?? null);
            }

            return $twig->render($response, 'admin/themes/browse.twig', $withCmsUser($request, array_merge($adminContext(), [
                'admin_nav' => 'themes',
                'catalog_ok' => $loaded['ok'],
                'catalog_error' => $loaded['ok'] ? null : $loaded['error'],
                'catalog_themes' => $loaded['ok'] ? $loaded['entries'] : [],
                'installed_theme_slugs' => array_fill_keys(array_keys($installed), true),
                'installed_theme_updates' => $installedUpdates,
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

        $group->post('/themes/install-upload', function (Request $request, Response $response) use ($remoteInstaller): Response {
            $parser = RouteContext::fromRequest($request)->getRouteParser();
            $backBrowse = $parser->urlFor('admin.themes.browse');
            $uploaded = PackageZipUploadReader::read($request->getUploadedFiles(), 35_000_000);
            if ($uploaded['ok'] !== true) {
                Flash::set('error', $uploaded['error']);

                return $response->withHeader('Location', $backBrowse)->withStatus(302);
            }
            $err = $remoteInstaller->installFromZipBody($uploaded['body']);
            if ($err !== null) {
                Flash::set('error', $err);

                return $response->withHeader('Location', $backBrowse)->withStatus(302);
            }
            Flash::set('success', 'Theme installed from upload. You can activate it from the themes list.');
            Events::dispatch(new StorefrontCachesInvalidateEvent('theme_installed_upload'));

            return $response
                ->withHeader('Location', $parser->urlFor('admin.themes.index'))
                ->withStatus(302);
        })->setName('admin.themes.install_upload');

        $group->post('/themes/update', function (Request $request, Response $response) use (
            $themes,
            $catalogLoader,
            $remoteInstaller,
            $themeUpdateChecker
        ): Response {
            $parser = RouteContext::fromRequest($request)->getRouteParser();
            $back = $parser->urlFor('admin.themes.index');
            $body = $request->getParsedBody();
            $body = is_array($body) ? $body : [];
            $slug = strtolower(trim((string) ($body['theme_slug'] ?? '')));
            if ($slug === '' || !ThemeManifest::isValidSlug($slug)) {
                Flash::set('error', 'Invalid theme.');

                return $response->withHeader('Location', $back)->withStatus(302);
            }

            $installed = $themes->findBySlug($slug);
            if ($installed === null) {
                Flash::set('error', 'Theme not found on disk.');

                return $response->withHeader('Location', $back)->withStatus(302);
            }

            $catalogBySlug = $themeUpdateChecker->catalogEntriesBySlug();
            $updateStatus = $themeUpdateChecker->statusFor($installed, $catalogBySlug[$slug] ?? null);
            if (!$updateStatus['update_available']) {
                Flash::set('error', 'No update is available for this theme.');

                return $response->withHeader('Location', $back)->withStatus(302);
            }
            if (!$updateStatus['can_update']) {
                Flash::set('error', 'An update was detected but no catalog or GitHub source is configured to download it.');

                return $response->withHeader('Location', $back)->withStatus(302);
            }

            $err = null;
            $repoUrl = $installed->repositoryUrl ?? '';
            $github = is_string($repoUrl) && $repoUrl !== ''
                ? PluginUpdateChecker::parseGithubRepositoryUrl($repoUrl)
                : null;

            if ($github !== null && $updateStatus['source'] === 'github') {
                $err = $remoteInstaller->updateFromGithubRepository(
                    $slug,
                    $github['owner'],
                    $github['repo'],
                    PluginUpdateChecker::resolveGithubRef(),
                );
            } elseif ($updateStatus['source'] === 'catalog' && isset($catalogBySlug[$slug])) {
                $loaded = $catalogLoader->load();
                if (!$loaded['ok']) {
                    Flash::set('error', 'Theme catalog is unavailable: ' . $loaded['error']);

                    return $response->withHeader('Location', $back)->withStatus(302);
                }
                $err = $remoteInstaller->updateFromCatalogSlug($slug, $loaded['entries']);
            } else {
                $err = 'This theme has no GitHub repository URL or catalog entry for updates.';
            }

            if ($err !== null) {
                Flash::set('error', $err);

                return $response->withHeader('Location', $back)->withStatus(302);
            }

            $updated = $themes->findBySlug($slug);
            if ($updated === null) {
                Flash::set('error', 'Theme update finished but the package could not be re-read from disk.');

                return $response->withHeader('Location', $back)->withStatus(302);
            }

            Flash::set('success', 'Theme updated to v' . $updated->version . '.');
            Events::dispatch(new StorefrontCachesInvalidateEvent('theme_updated'));

            return $response->withHeader('Location', $back)->withStatus(302);
        })->setName('admin.themes.update');

        $group->post('/themes/reinstall-catalog', function (Request $request, Response $response) use (
            $themes,
            $catalogLoader,
            $remoteInstaller,
            $themeUpdateChecker,
        ): Response {
            $parser = RouteContext::fromRequest($request)->getRouteParser();
            $back = $parser->urlFor('admin.themes.index');
            $body = $request->getParsedBody();
            $body = is_array($body) ? $body : [];
            $slug = strtolower(trim((string) ($body['theme_slug'] ?? '')));
            if ($slug === '' || !ThemeManifest::isValidSlug($slug)) {
                Flash::set('error', 'Invalid theme.');

                return $response->withHeader('Location', $back)->withStatus(302);
            }

            if ($themes->findBySlug($slug) === null) {
                Flash::set('error', 'Theme not found on disk.');

                return $response->withHeader('Location', $back)->withStatus(302);
            }

            $catalogBySlug = $themeUpdateChecker->catalogEntriesBySlug();
            if (!isset($catalogBySlug[$slug]) || trim($catalogBySlug[$slug]->downloadUrl) === '') {
                Flash::set('error', 'No catalog ZIP is configured for this theme. Regenerate the Struxa catalog on this server first.');

                return $response->withHeader('Location', $back)->withStatus(302);
            }

            $loaded = $catalogLoader->load();
            if (!$loaded['ok']) {
                Flash::set('error', 'Theme catalog is unavailable: ' . $loaded['error']);

                return $response->withHeader('Location', $back)->withStatus(302);
            }

            $err = $remoteInstaller->updateFromCatalogSlug($slug, $loaded['entries']);
            if ($err !== null) {
                Flash::set('error', $err);

                return $response->withHeader('Location', $back)->withStatus(302);
            }

            $updated = $themes->findBySlug($slug);
            if ($updated === null) {
                Flash::set('error', 'Theme reinstall finished but the package could not be re-read from disk.');

                return $response->withHeader('Location', $back)->withStatus(302);
            }

            Flash::set('success', 'Theme reinstalled from catalog (v' . $updated->version . ').');
            Events::dispatch(new StorefrontCachesInvalidateEvent('theme_reinstalled_catalog'));

            return $response->withHeader('Location', $back)->withStatus(302);
        })->setName('admin.themes.reinstall_catalog');

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
