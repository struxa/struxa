<?php

declare(strict_types=1);

use App\Auth\AppAuth;
use App\Mobile\MobileAuthException;
use App\Mobile\MobileAuthService;
use App\Mobile\MobileBootstrapService;
use App\Mobile\MobileCommerceException;
use App\Mobile\MobileCommerceService;
use App\Mobile\MobileContentException;
use App\Mobile\MobileContentService;
use App\Mobile\MobileSettings;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Slim\Exception\HttpNotFoundException;

/**
 * Public mobile discovery + bootstrap (no API key; intended for Struxa client apps).
 *
 * @param callable(): array<string, mixed> $viewData
 */
return static function (App $app, \PDO $pdo, \App\Theme\ThemeManager $themeManager, AppAuth $auth, callable $viewData): void {
    $bootstrap = new MobileBootstrapService($pdo, $themeManager);
    $content = new MobileContentService($pdo);
    $commerceApi = new MobileCommerceService($pdo);
    $mobileAuth = new MobileAuthService($pdo, $auth);

    $json = static function (Response $response, array $payload, int $status = 200, bool $private = false): Response {
        $response->getBody()->write(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $response = $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json; charset=utf-8');

        return $response->withHeader(
            'Cache-Control',
            $private ? 'no-store, no-cache, must-revalidate' : 'public, max-age=120',
        );
    };

    $parseBody = static function (Request $request): array {
        $body = $request->getParsedBody();
        return is_array($body) ? $body : [];
    };

    $authError = static function (Response $response, MobileAuthException $e) use ($json): Response {
        return $json($response, [
            'ok' => false,
            'error' => $e->errorCode,
            'message' => $e->getMessage(),
        ], $e->httpStatus, true);
    };

    /** Catch DB / unexpected failures so the app always gets JSON (not a Slim HTML error page). */
    $authAction = static function (Response $response, callable $run) use ($json, $authError): Response {
        try {
            return $run();
        } catch (MobileAuthException $e) {
            return $authError($response, $e);
        } catch (\PDOException $e) {
            $message = str_contains($e->getMessage(), 'cms_mobile_refresh_tokens')
                ? 'Mobile sign-in is not set up on this site yet. Run database migrations (056_mobile_auth).'
                : 'Mobile auth database error. Check that CMS migrations are up to date.';

            return $json($response, [
                'ok' => false,
                'error' => 'database_error',
                'message' => $message,
            ], 503, true);
        } catch (\Throwable) {
            return $json($response, [
                'ok' => false,
                'error' => 'server_error',
                'message' => 'Sign-in failed due to a server error.',
            ], 500, true);
        }
    };

    $commerceError = static function (Response $response, MobileCommerceException $e) use ($json): Response {
        return $json($response, [
            'ok' => false,
            'error' => $e->errorCode,
            'message' => $e->getMessage(),
        ], $e->httpStatus, true);
    };

    $mobileDisabled = static function (Response $response) use ($json): Response {
        return $json($response, [
            'ok' => false,
            'error' => 'mobile_disabled',
            'message' => 'Mobile app access is disabled for this site.',
        ], 403);
    };

    $app->get('/api/v1/mobile/bootstrap', function (Request $request, Response $response) use ($bootstrap, $json, $mobileDisabled): Response {
        if (!MobileSettings::enabled()) {
            return $mobileDisabled($response);
        }

        return $json($response, [
            'ok' => true,
            'data' => $bootstrap->build(),
        ]);
    })->setName('public.mobile.bootstrap');

    $app->get('/.well-known/struxa.json', function (Request $request, Response $response) use ($bootstrap, $json): Response {
        if (!MobileSettings::enabled()) {
            throw new HttpNotFoundException($request);
        }

        return $json($response, $bootstrap->wellKnown());
    })->setName('public.mobile.well_known');

    $app->get('/api/v1/mobile/content/{typeSlug}/entries', function (
        Request $request,
        Response $response,
        array $args
    ) use ($content, $json, $mobileDisabled): Response {
        if (!MobileSettings::enabled()) {
            return $mobileDisabled($response);
        }

        $typeSlug = (string) ($args['typeSlug'] ?? '');
        $query = $request->getQueryParams();
        $page = isset($query['page']) && is_numeric($query['page']) ? (int) $query['page'] : 1;
        $perPage = isset($query['per_page']) && is_numeric($query['per_page'])
            ? (int) $query['per_page']
            : MobileContentService::PER_PAGE_DEFAULT;

        try {
            $result = $content->listEntries($typeSlug, $page, $perPage);
        } catch (MobileContentException $e) {
            $status = $e->errorCode === 'mobile_disabled' ? 403 : 404;

            return $json($response, [
                'ok' => false,
                'error' => $e->errorCode,
                'message' => $e->getMessage(),
            ], $status);
        }

        return $json($response, [
            'ok' => true,
            'meta' => $result['meta'],
            'data' => $result['items'],
        ]);
    })->setName('public.mobile.content.entries');

    $app->get('/api/v1/mobile/content/{typeSlug}/entries/{entrySlug}', function (
        Request $request,
        Response $response,
        array $args
    ) use ($content, $json, $mobileDisabled): Response {
        if (!MobileSettings::enabled()) {
            return $mobileDisabled($response);
        }

        $typeSlug = (string) ($args['typeSlug'] ?? '');
        $entrySlug = (string) ($args['entrySlug'] ?? '');

        try {
            $data = $content->entryDetail($typeSlug, $entrySlug);
        } catch (MobileContentException $e) {
            $status = $e->errorCode === 'mobile_disabled' ? 403 : 404;

            return $json($response, [
                'ok' => false,
                'error' => $e->errorCode,
                'message' => $e->getMessage(),
            ], $status);
        }

        return $json($response, [
            'ok' => true,
            'data' => $data,
        ]);
    })->setName('public.mobile.content.entry');

    $app->post('/api/v1/mobile/auth/login', function (Request $request, Response $response) use ($mobileAuth, $json, $parseBody, $authAction): Response {
        $body = $parseBody($request);

        return $authAction($response, function () use ($mobileAuth, $json, $response, $body): Response {
            $data = $mobileAuth->login(
                (string) ($body['email'] ?? ''),
                (string) ($body['password'] ?? ''),
                (string) ($body['totp_code'] ?? ''),
            );

            return $json($response, ['ok' => true, 'data' => $data], 200, true);
        });
    })->setName('public.mobile.auth.login');

    $app->post('/api/v1/mobile/auth/register', function (Request $request, Response $response) use ($mobileAuth, $json, $parseBody, $authAction): Response {
        $body = $parseBody($request);

        return $authAction($response, function () use ($mobileAuth, $json, $response, $body): Response {
            $data = $mobileAuth->register(
                (string) ($body['email'] ?? ''),
                (string) ($body['password'] ?? ''),
                (string) ($body['password_confirm'] ?? $body['password'] ?? ''),
                (string) ($body['username'] ?? ''),
            );

            return $json($response, ['ok' => true, 'data' => $data], 200, true);
        });
    })->setName('public.mobile.auth.register');

    $app->post('/api/v1/mobile/auth/refresh', function (Request $request, Response $response) use ($mobileAuth, $json, $parseBody, $authAction): Response {
        $body = $parseBody($request);

        return $authAction($response, function () use ($mobileAuth, $json, $response, $body): Response {
            $data = $mobileAuth->refresh((string) ($body['refresh_token'] ?? ''));

            return $json($response, ['ok' => true, 'data' => $data], 200, true);
        });
    })->setName('public.mobile.auth.refresh');

    $app->post('/api/v1/mobile/auth/logout', function (Request $request, Response $response) use ($mobileAuth, $json, $parseBody, $authAction): Response {
        $body = $parseBody($request);

        return $authAction($response, function () use ($mobileAuth, $json, $response, $body): Response {
            $mobileAuth->logout((string) ($body['refresh_token'] ?? ''));

            return $json($response, ['ok' => true], 200, true);
        });
    })->setName('public.mobile.auth.logout');

    $app->get('/api/v1/mobile/auth/me', function (Request $request, Response $response) use ($mobileAuth, $json, $authAction): Response {
        return $authAction($response, function () use ($mobileAuth, $json, $response, $request): Response {
            $authCtx = $mobileAuth->authenticateAccessToken($request->getHeaderLine('Authorization'));
            $data = $mobileAuth->me($authCtx['userId']);

            return $json($response, ['ok' => true, 'data' => $data], 200, true);
        });
    })->setName('public.mobile.auth.me');

    $app->get('/api/v1/mobile/commerce/config', function (Request $request, Response $response) use ($commerceApi, $json, $mobileDisabled): Response {
        if (!MobileSettings::enabled()) {
            return $mobileDisabled($response);
        }

        try {
            $data = $commerceApi->commerceConfig();
        } catch (MobileCommerceException $e) {
            return $commerceError($response, $e);
        }

        return $json($response, ['ok' => true, 'data' => $data]);
    })->setName('public.mobile.commerce.config');

    $app->get('/api/v1/mobile/commerce/products', function (Request $request, Response $response) use ($commerceApi, $json, $mobileDisabled): Response {
        if (!MobileSettings::enabled()) {
            return $mobileDisabled($response);
        }

        $query = $request->getQueryParams();
        $page = isset($query['page']) && is_numeric($query['page']) ? (int) $query['page'] : 1;
        $perPage = isset($query['per_page']) && is_numeric($query['per_page'])
            ? (int) $query['per_page']
            : MobileCommerceService::PER_PAGE_DEFAULT;

        try {
            $result = $commerceApi->listProducts($page, $perPage);
        } catch (MobileCommerceException $e) {
            return $commerceError($response, $e);
        }

        return $json($response, [
            'ok' => true,
            'meta' => $result['meta'],
            'data' => $result['items'],
        ]);
    })->setName('public.mobile.commerce.products');

    $app->get('/api/v1/mobile/commerce/products/{entrySlug}', function (
        Request $request,
        Response $response,
        array $args
    ) use ($commerceApi, $json, $mobileDisabled, $commerceError): Response {
        if (!MobileSettings::enabled()) {
            return $mobileDisabled($response);
        }

        try {
            $data = $commerceApi->productDetail((string) ($args['entrySlug'] ?? ''));
        } catch (MobileCommerceException $e) {
            return $commerceError($response, $e);
        }

        return $json($response, ['ok' => true, 'data' => $data]);
    })->setName('public.mobile.commerce.product');

    $app->post('/api/v1/mobile/commerce/cart/quote', function (Request $request, Response $response) use ($commerceApi, $json, $parseBody, $mobileDisabled, $commerceError): Response {
        if (!MobileSettings::enabled()) {
            return $mobileDisabled($response);
        }

        $body = $parseBody($request);
        $lines = isset($body['lines']) && is_array($body['lines']) ? $body['lines'] : [];
        $shipCountry = isset($body['ship_country']) && is_string($body['ship_country']) ? $body['ship_country'] : null;
        $couponCode = isset($body['coupon_code']) && is_string($body['coupon_code']) ? $body['coupon_code'] : null;

        try {
            $data = $commerceApi->quoteCart($lines, $shipCountry, $couponCode);
        } catch (MobileCommerceException $e) {
            return $commerceError($response, $e);
        }

        return $json($response, ['ok' => true, 'data' => $data], 200, true);
    })->setName('public.mobile.commerce.cart.quote');

    $app->post('/api/v1/mobile/commerce/checkout', function (Request $request, Response $response) use ($commerceApi, $mobileAuth, $json, $parseBody, $mobileDisabled, $commerceError, $authError): Response {
        if (!MobileSettings::enabled()) {
            return $mobileDisabled($response);
        }

        $body = $parseBody($request);
        $lines = isset($body['lines']) && is_array($body['lines']) ? $body['lines'] : [];
        $shipCountry = isset($body['ship_country']) && is_string($body['ship_country']) ? $body['ship_country'] : null;
        $couponCode = isset($body['coupon_code']) && is_string($body['coupon_code']) ? $body['coupon_code'] : null;

        $customerUserId = null;
        $authHeader = trim($request->getHeaderLine('Authorization'));
        if ($authHeader !== '') {
            try {
                $authCtx = $mobileAuth->authenticateAccessToken($authHeader);
                $customerUserId = $authCtx['userId'];
            } catch (MobileAuthException $e) {
                return $authError($response, $e);
            }
        }

        try {
            $data = $commerceApi->startCheckout($lines, $shipCountry, $couponCode, $customerUserId);
        } catch (MobileCommerceException $e) {
            return $commerceError($response, $e);
        }

        return $json($response, ['ok' => true, 'data' => $data], 200, true);
    })->setName('public.mobile.commerce.checkout');

    $app->get('/api/v1/mobile/commerce/orders', function (Request $request, Response $response) use ($commerceApi, $mobileAuth, $json, $mobileDisabled, $commerceError, $authError): Response {
        if (!MobileSettings::enabled()) {
            return $mobileDisabled($response);
        }

        try {
            $authCtx = $mobileAuth->authenticateAccessToken($request->getHeaderLine('Authorization'));
            $profile = $mobileAuth->me($authCtx['userId']);
            $data = $commerceApi->listOrders($authCtx['userId'], (string) ($profile['email'] ?? ''));
        } catch (MobileAuthException $e) {
            return $authError($response, $e);
        } catch (MobileCommerceException $e) {
            return $commerceError($response, $e);
        }

        return $json($response, ['ok' => true, 'data' => $data], 200, true);
    })->setName('public.mobile.commerce.orders');

    $app->get('/api/v1/mobile/commerce/orders/{orderNumber}', function (
        Request $request,
        Response $response,
        array $args
    ) use ($commerceApi, $mobileAuth, $json, $mobileDisabled, $commerceError, $authError): Response {
        if (!MobileSettings::enabled()) {
            return $mobileDisabled($response);
        }

        try {
            $authCtx = $mobileAuth->authenticateAccessToken($request->getHeaderLine('Authorization'));
            $profile = $mobileAuth->me($authCtx['userId']);
            $data = $commerceApi->orderDetail(
                (string) ($args['orderNumber'] ?? ''),
                $authCtx['userId'],
                (string) ($profile['email'] ?? ''),
            );
        } catch (MobileAuthException $e) {
            return $authError($response, $e);
        } catch (MobileCommerceException $e) {
            return $commerceError($response, $e);
        }

        return $json($response, ['ok' => true, 'data' => $data], 200, true);
    })->setName('public.mobile.commerce.order');

    $app->get('/api/v1/mobile/commerce/downloads', function (Request $request, Response $response) use ($commerceApi, $mobileAuth, $json, $mobileDisabled, $commerceError, $authError): Response {
        if (!MobileSettings::enabled()) {
            return $mobileDisabled($response);
        }

        try {
            $authCtx = $mobileAuth->authenticateAccessToken($request->getHeaderLine('Authorization'));
            $profile = $mobileAuth->me($authCtx['userId']);
            $data = $commerceApi->listDigitalDownloads($authCtx['userId'], (string) ($profile['email'] ?? ''));
        } catch (MobileAuthException $e) {
            return $authError($response, $e);
        } catch (MobileCommerceException $e) {
            return $commerceError($response, $e);
        }

        return $json($response, ['ok' => true, 'data' => $data], 200, true);
    })->setName('public.mobile.commerce.downloads');
};
