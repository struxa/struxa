<?php

declare(strict_types=1);

use App\Mobile\MobileBootstrapService;
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
return static function (App $app, \PDO $pdo, \App\Theme\ThemeManager $themeManager, callable $viewData): void {
    $bootstrap = new MobileBootstrapService($pdo, $themeManager);
    $content = new MobileContentService($pdo);

    $json = static function (Response $response, array $payload, int $status = 200): Response {
        $response->getBody()->write(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withHeader('Cache-Control', 'public, max-age=120');
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
};
