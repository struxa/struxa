<?php

declare(strict_types=1);

use App\Mobile\MobileBootstrapService;
use App\Mobile\MobileSettings;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;

/**
 * Public mobile discovery + bootstrap (no API key; intended for Struxa client apps).
 *
 * @param callable(): array<string, mixed> $viewData
 */
return static function (App $app, \PDO $pdo, \App\Theme\ThemeManager $themeManager, callable $viewData): void {
    $service = new MobileBootstrapService($pdo, $themeManager);

    $json = static function (Response $response, array $payload, int $status = 200): Response {
        $response->getBody()->write(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withHeader('Cache-Control', 'public, max-age=120');
    };

    $app->get('/api/v1/mobile/bootstrap', function (Request $request, Response $response) use ($service, $json): Response {
        if (!MobileSettings::enabled()) {
            return $json($response, [
                'ok' => false,
                'error' => 'mobile_disabled',
                'message' => 'Mobile app access is disabled for this site.',
            ], 403);
        }

        return $json($response, [
            'ok' => true,
            'data' => $service->build(),
        ]);
    })->setName('public.mobile.bootstrap');

    $app->get('/.well-known/struxa.json', function (Request $request, Response $response) use ($service, $json): Response {
        if (!MobileSettings::enabled()) {
            throw new \Slim\Exception\HttpNotFoundException($request);
        }

        return $json($response, $service->wellKnown());
    })->setName('public.mobile.well_known');
};
