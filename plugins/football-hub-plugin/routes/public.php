<?php

declare(strict_types=1);

use App\Plugin\PluginBootContext;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;

return function (App $app, PluginBootContext $ctx): void {
    $pdo = $ctx->pdo();

    $app->group('/football', function (\Slim\Routing\RouteCollectorProxy $group) use ($pdo): void {
        $group->get('/health', function (Request $request, Response $response) use ($pdo): Response {
            try {
                $pdo->query('SELECT 1');
                $ok = true;
            } catch (\Throwable) {
                $ok = false;
            }
            $response->getBody()->write(json_encode(['ok' => $ok, 'plugin' => 'football-hub-plugin']));

            return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
        });
    });
};
