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

        $firebaseServiceAccountStoredFlag = static function () use ($settingsRepo): bool {
            $db = $settingsRepo->allKeyValues();

            return trim((string) ($db['firebase_service_account_json'] ?? '')) !== '';
        };

        $group->get('/settings', function (Request $request, Response $response) use (
            $twig,
            $adminContext,
            $withCmsUser,
            $service,
            $mediaRepo,
            $pageRepo,
            $googleSecretStoredFlag,
            $firebaseServiceAccountStoredFlag
        ): Response {
            return $twig->render($response, 'admin/settings/form.twig', $withCmsUser($request, array_merge($adminContext(), [
                'admin_nav' => 'settings',
                'settings_values' => $service->forForm(),
                'site_language_options' => SiteLocale::formOptions(),
                'media_picker_images' => $mediaRepo->listImagesForPicker(200),
                'published_pages_for_home' => $pageRepo->publishedIdTitlePairs(),
                'google_oauth_secret_stored' => $googleSecretStoredFlag(),
                'firebase_service_account_stored' => $firebaseServiceAccountStoredFlag(),
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
            $googleSecretStoredFlag,
            $firebaseServiceAccountStoredFlag
        ): Response {
            $body = $request->getParsedBody();
            $body = is_array($body) ? $body : [];
            $result = $validator->validate($body, $mediaRepo);

            if ($result['errors'] !== []) {
                $result['values']['google_oauth_client_secret'] = '';
                $result['values']['firebase_service_account_json'] = '';

                return $twig->render($response, 'admin/settings/form.twig', $withCmsUser($request, array_merge($adminContext(), [
                    'admin_nav' => 'settings',
                    'settings_values' => $result['values'],
                    'site_language_options' => SiteLocale::formOptions(),
                    'media_picker_images' => $mediaRepo->listImagesForPicker(200),
                    'published_pages_for_home' => $pageRepo->publishedIdTitlePairs(),
                    'google_oauth_secret_stored' => $googleSecretStoredFlag(),
                    'firebase_service_account_stored' => $firebaseServiceAccountStoredFlag(),
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

            if (!empty($body['firebase_service_account_json_clear'])) {
                $result['values']['firebase_service_account_json'] = '';
            } else {
                $typedSa = trim((string) ($result['values']['firebase_service_account_json'] ?? ''));
                $result['values']['firebase_service_account_json'] = $typedSa !== ''
                    ? $typedSa
                    : (string) ($dbVals['firebase_service_account_json'] ?? '');
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

            if (($result['values']['firebase_enabled'] ?? '0') === '1') {
                foreach ([
                    'firebase_api_key' => 'Web API key',
                    'firebase_auth_domain' => 'Auth domain',
                    'firebase_project_id' => 'Project ID',
                    'firebase_app_id' => 'App ID',
                ] as $field => $label) {
                    if (trim((string) ($result['values'][$field] ?? '')) === '') {
                        $result['errors'][$field] = $label . ' is required when Firebase sign-in is enabled.';
                    }
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
                $result['values']['firebase_service_account_json'] = '';

                return $twig->render($response, 'admin/settings/form.twig', $withCmsUser($request, array_merge($adminContext(), [
                    'admin_nav' => 'settings',
                    'settings_values' => $result['values'],
                    'site_language_options' => SiteLocale::formOptions(),
                    'media_picker_images' => $mediaRepo->listImagesForPicker(200),
                    'published_pages_for_home' => $pageRepo->publishedIdTitlePairs(),
                    'google_oauth_secret_stored' => $googleSecretStoredFlag(),
                    'firebase_service_account_stored' => $firebaseServiceAccountStoredFlag(),
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
