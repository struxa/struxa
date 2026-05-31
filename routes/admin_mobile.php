<?php

declare(strict_types=1);

use App\Access\PermissionSlug;
use App\Flash;
use App\Http\Middleware\RequireCmsStaff;
use App\Http\Middleware\RequirePermission;
use App\Mobile\MobileBootstrapService;
use App\Mobile\MobileSettings;
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

    $app->get('/admin/settings/mobile', function (
        Request $request,
        Response $response
    ) use ($twig, $pdo, $themeManager, $adminContext, $withCmsUser, $bootstrapUrl, $wellKnownUrl): Response {
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

        return $twig->render($response, 'admin/settings/mobile.twig', $withCmsUser($request, array_merge($adminContext(), [
            'admin_nav' => 'settings_mobile',
            'mobile_enabled' => MobileSettings::enabled(),
            'mobile_welcome_title' => MobileSettings::welcomeTitle(),
            'mobile_welcome_message' => MobileSettings::welcomeMessage(),
            'mobile_include_footer_nav' => MobileSettings::includeFooterNav(),
            'mobile_tabs_json' => (string) (\App\Settings::get(MobileSettings::SETTING_TABS_JSON, '') ?? ''),
            'mobile_bootstrap_url' => $bootstrapUrl,
            'mobile_well_known_url' => $wellKnownUrl,
            'mobile_preview' => $preview,
            'mobile_preview_json' => $previewJson,
        ])));
    })->setName('admin.settings.mobile')->add($perm)->add($middleware);

    $app->post('/admin/settings/mobile', function (
        Request $request,
        Response $response
    ) use ($pdo): Response {
        $body = (array) $request->getParsedBody();
        $enabled = !empty($body['enabled']);
        $includeFooter = !empty($body['include_footer_nav']);
        $welcomeTitle = isset($body['welcome_title']) && is_string($body['welcome_title']) ? $body['welcome_title'] : '';
        $welcomeMessage = isset($body['welcome_message']) && is_string($body['welcome_message']) ? $body['welcome_message'] : '';
        $tabsJson = isset($body['tabs_json']) && is_string($body['tabs_json']) ? $body['tabs_json'] : '';

        MobileSettings::save($pdo, $enabled, $welcomeTitle, $welcomeMessage, $includeFooter, $tabsJson);
        Flash::set('success', 'Mobile app settings saved.');

        $url = RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.settings.mobile');

        return $response->withHeader('Location', $url)->withStatus(302);
    })->setName('admin.settings.mobile.save')->add($perm)->add($middleware);
};
