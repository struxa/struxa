<?php

declare(strict_types=1);

use App\Auth\AppAuth;
use App\CmsUserRepository;
use App\Flash;
use App\Http\Middleware\RequireCmsStaff;
use App\Security\TotpEnrollmentSession;
use App\Security\TotpService;
use App\Settings;
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

    $adminContext = static fn (): array => array_merge($viewData(), []);
    $withCmsUser = static function (Request $request, array $data): array {
        /** @var array<string, mixed> $cmsUser */
        $cmsUser = $request->getAttribute('cms_user') ?? [];

        return array_merge($data, ['cms_user' => $cmsUser]);
    };

    $issuer = Settings::get('site_name') ?? 'CMS';
    $totp = new TotpService($issuer);

    $app->get('/admin/account/two-factor', function (Request $request, Response $response) use ($twig, $adminContext, $withCmsUser, $pdo, $auth, $totp): Response {
        /** @var array<string, mixed> $cu */
        $cu = $request->getAttribute('cms_user') ?? [];
        $cmsId = (int) ($cu['id'] ?? 0);
        $phpauthId = (int) ($auth->getCurrentUID());
        $state = CmsUserRepository::findTotpStateByPhpAuthId($pdo, $phpauthId);
        $enabled = $state !== null && (int) ($state['totp_enabled'] ?? 0) === 1;

        $pending = TotpEnrollmentSession::get();
        $showQr = false;
        $qrUri = '';
        $secret = '';
        if (!$enabled && $pending !== null && $pending['cms_user_id'] === $cmsId) {
            $showQr = true;
            $secret = $pending['secret'];
            $label = (string) ($cu['email'] ?? 'user');
            $qrUri = $totp->getQrDataUri($label, $secret);
        }

        return $twig->render($response, 'admin/account/two_factor.twig', $withCmsUser($request, array_merge($adminContext(), [
            'totp_enabled' => $enabled,
            'enroll_qr_uri' => $showQr ? $qrUri : null,
            'enroll_secret' => $showQr ? $secret : null,
        ])));
    })->setName('admin.account.two_factor')->add($middleware);

    $app->post('/admin/account/two-factor/start', function (Request $request, Response $response) use ($pdo, $auth, $totp): Response {
        /** @var array<string, mixed> $cu */
        $cu = $request->getAttribute('cms_user') ?? [];
        $cmsId = (int) ($cu['id'] ?? 0);
        $phpauthId = (int) $auth->getCurrentUID();
        $state = CmsUserRepository::findTotpStateByPhpAuthId($pdo, $phpauthId);
        if ($state !== null && (int) ($state['totp_enabled'] ?? 0) === 1) {
            Flash::set('error', 'Two-factor authentication is already enabled.');

            return $response
                ->withHeader('Location', RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.account.two_factor'))
                ->withStatus(302);
        }

        $secret = $totp->createSecret();
        TotpEnrollmentSession::put($cmsId, $secret);

        return $response
            ->withHeader('Location', RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.account.two_factor'))
            ->withStatus(302);
    })->setName('admin.account.two_factor.start')->add($middleware);

    $app->post('/admin/account/two-factor/confirm', function (Request $request, Response $response) use ($pdo, $totp): Response {
        $routeParser = RouteContext::fromRequest($request)->getRouteParser();
        $back = $routeParser->urlFor('admin.account.two_factor');

        /** @var array<string, mixed> $cu */
        $cu = $request->getAttribute('cms_user') ?? [];
        $cmsId = (int) ($cu['id'] ?? 0);
        $pending = TotpEnrollmentSession::get();
        if ($pending === null || $pending['cms_user_id'] !== $cmsId) {
            Flash::set('error', 'Setup expired or not started. Try again.');

            return $response->withHeader('Location', $back)->withStatus(302);
        }

        $body = $request->getParsedBody();
        $code = is_array($body) ? trim((string) ($body['code'] ?? '')) : '';
        if (!$totp->verify($pending['secret'], $code)) {
            Flash::set('error', 'Invalid code. Check the time on your device and try again.');

            return $response->withHeader('Location', $back)->withStatus(302);
        }

        CmsUserRepository::updateTotpSecret($pdo, $cmsId, $pending['secret']);
        CmsUserRepository::setTotpEnabled($pdo, $cmsId, true);
        TotpEnrollmentSession::clear();
        Flash::set('success', 'Two-factor authentication is now enabled.');

        return $response->withHeader('Location', $back)->withStatus(302);
    })->setName('admin.account.two_factor.confirm')->add($middleware);

    $app->post('/admin/account/two-factor/disable', function (Request $request, Response $response) use ($pdo, $auth, $totp): Response {
        $routeParser = RouteContext::fromRequest($request)->getRouteParser();
        $back = $routeParser->urlFor('admin.account.two_factor');

        if (!$auth instanceof AppAuth) {
            Flash::set('error', 'Two-factor disable is unavailable.');

            return $response->withHeader('Location', $back)->withStatus(302);
        }

        /** @var array<string, mixed> $cu */
        $cu = $request->getAttribute('cms_user') ?? [];
        $cmsId = (int) ($cu['id'] ?? 0);
        $phpauthId = (int) $auth->getCurrentUID();
        $state = CmsUserRepository::findTotpStateByPhpAuthId($pdo, $phpauthId);
        if ($state === null || (int) ($state['totp_enabled'] ?? 0) !== 1) {
            Flash::set('error', 'Two-factor authentication is not enabled.');

            return $response->withHeader('Location', $back)->withStatus(302);
        }

        $secret = (string) ($state['totp_secret'] ?? '');
        if ($secret === '') {
            CmsUserRepository::updateTotpSecret($pdo, $cmsId, null);
            CmsUserRepository::setTotpEnabled($pdo, $cmsId, false);
            Flash::set('success', 'Two-factor authentication has been disabled.');

            return $response->withHeader('Location', $back)->withStatus(302);
        }

        $body = $request->getParsedBody();
        $password = is_array($body) ? (string) ($body['password'] ?? '') : '';
        $code = is_array($body) ? trim((string) ($body['code'] ?? '')) : '';

        $user = $auth->getCurrentUser();
        $email = is_array($user) ? trim((string) ($user['email'] ?? '')) : '';
        if ($email === '') {
            Flash::set('error', 'Could not verify your account.');

            return $response->withHeader('Location', $back)->withStatus(302);
        }

        $pre = $auth->verifyPasswordPreSession($email, $password, 0, '');
        if (($pre['error'] ?? true) === true) {
            Flash::set('error', (string) ($pre['message'] ?? 'Incorrect password.'));

            return $response->withHeader('Location', $back)->withStatus(302);
        }

        if (!$totp->verify($secret, $code)) {
            Flash::set('error', 'Invalid authenticator code.');

            return $response->withHeader('Location', $back)->withStatus(302);
        }

        CmsUserRepository::updateTotpSecret($pdo, $cmsId, null);
        CmsUserRepository::setTotpEnabled($pdo, $cmsId, false);
        TotpEnrollmentSession::clear();
        Flash::set('success', 'Two-factor authentication has been disabled.');

        return $response->withHeader('Location', $back)->withStatus(302);
    })->setName('admin.account.two_factor.disable')->add($middleware);
};
