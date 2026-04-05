<?php

declare(strict_types=1);

use App\Access\PermissionSlug;
use App\Event\Events;
use App\Event\StorefrontCachesInvalidateEvent;
use App\Flash;
use App\Locale\SiteLocale;
use App\Http\Middleware\RequireCmsStaff;
use App\Http\Middleware\RequirePermission;
use App\Media\MediaRepository;
use App\Page\PageRepository;
use App\Settings\SettingsFormValidator;
use App\Settings\SettingsRepository;
use App\Settings\SiteSettingsService;
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
    $permSettings = new RequirePermission($pdo, [PermissionSlug::MANAGE_SETTINGS]);
    $settingsRepo = new SettingsRepository($pdo);
    $service = new SiteSettingsService($settingsRepo);
    $validator = new SettingsFormValidator();
    $mediaRepo = new MediaRepository($pdo);
    $pageRepo = new PageRepository($pdo);

    $adminContext = static function () use ($viewData): array {
        return array_merge($viewData(), []);
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
        $service,
        $validator,
        $mediaRepo,
        $pageRepo,
        $pdo,
        $settingsRepo
    ): void {
        $googleSecretStoredFlag = static function () use ($settingsRepo): bool {
            $db = $settingsRepo->allKeyValues();

            return trim((string) ($db['google_oauth_client_secret'] ?? '')) !== '';
        };

        $group->get('/settings', function (Request $request, Response $response) use (
            $twig,
            $adminContext,
            $withCmsUser,
            $service,
            $mediaRepo,
            $pageRepo,
            $googleSecretStoredFlag
        ): Response {
            return $twig->render($response, 'admin/settings/form.twig', $withCmsUser($request, array_merge($adminContext(), [
                'admin_nav' => 'settings',
                'settings_values' => $service->forForm(),
                'site_language_options' => SiteLocale::formOptions(),
                'media_picker_images' => $mediaRepo->listImagesForPicker(200),
                'published_pages_for_home' => $pageRepo->publishedIdTitlePairs(),
                'google_oauth_secret_stored' => $googleSecretStoredFlag(),
                'errors' => [],
            ])));
        })->setName('admin.settings');

        $group->post('/settings', function (Request $request, Response $response) use (
            $twig,
            $adminContext,
            $withCmsUser,
            $service,
            $validator,
            $mediaRepo,
            $pageRepo,
            $pdo,
            $settingsRepo,
            $googleSecretStoredFlag
        ): Response {
            $body = $request->getParsedBody();
            $body = is_array($body) ? $body : [];
            $result = $validator->validate($body, $mediaRepo);

            if ($result['errors'] !== []) {
                $result['values']['google_oauth_client_secret'] = '';

                return $twig->render($response, 'admin/settings/form.twig', $withCmsUser($request, array_merge($adminContext(), [
                    'admin_nav' => 'settings',
                    'settings_values' => $result['values'],
                    'site_language_options' => SiteLocale::formOptions(),
                    'media_picker_images' => $mediaRepo->listImagesForPicker(200),
                    'published_pages_for_home' => $pageRepo->publishedIdTitlePairs(),
                    'google_oauth_secret_stored' => $googleSecretStoredFlag(),
                    'errors' => $result['errors'],
                ])));
            }

            $dbVals = $settingsRepo->allKeyValues();
            if (!empty($body['google_oauth_client_secret_clear'])) {
                $result['values']['google_oauth_client_secret'] = '';
            } else {
                $typed = trim((string) ($result['values']['google_oauth_client_secret'] ?? ''));
                $result['values']['google_oauth_client_secret'] = $typed !== ''
                    ? $typed
                    : (string) ($dbVals['google_oauth_client_secret'] ?? '');
            }

            if (($result['values']['google_sso_enabled'] ?? '0') === '1') {
                if (trim((string) ($result['values']['google_oauth_client_id'] ?? '')) === '') {
                    $result['errors']['google_oauth_client_id'] = 'Client ID is required when Google sign-in is enabled.';
                }
                if (trim((string) ($result['values']['google_oauth_client_secret'] ?? '')) === '') {
                    $result['errors']['google_oauth_client_secret'] =
                        'Client secret is required when Google sign-in is enabled. Paste a new secret or turn Google sign-in off until credentials are configured.';
                }
            }

            $hp = $result['values']['public_homepage_page_id'] ?? '';
            if ($result['errors'] === [] && $hp !== '') {
                $p = $pageRepo->findById((int) $hp);
                if ($p === null || $p->status !== 'published') {
                    $result['errors']['public_homepage_page_id'] = 'Choose a published page, or clear this field to use the theme homepage.';
                }
            }

            if ($result['errors'] !== []) {
                $result['values']['google_oauth_client_secret'] = '';

                return $twig->render($response, 'admin/settings/form.twig', $withCmsUser($request, array_merge($adminContext(), [
                    'admin_nav' => 'settings',
                    'settings_values' => $result['values'],
                    'site_language_options' => SiteLocale::formOptions(),
                    'media_picker_images' => $mediaRepo->listImagesForPicker(200),
                    'published_pages_for_home' => $pageRepo->publishedIdTitlePairs(),
                    'google_oauth_secret_stored' => $googleSecretStoredFlag(),
                    'errors' => $result['errors'],
                ])));
            }

            $toSave = [];
            foreach (SiteSettingsService::MANAGED_KEYS as $key) {
                $toSave[$key] = $result['values'][$key] ?? '';
            }
            $service->save($toSave, $pdo);
            Flash::set('success', 'Settings saved.');
            Events::dispatch(new StorefrontCachesInvalidateEvent('site_settings'));

            return $response
                ->withHeader('Location', RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.settings'))
                ->withStatus(302);
        })->setName('admin.settings.save');
    })->add($permSettings)->add($middleware);
};
