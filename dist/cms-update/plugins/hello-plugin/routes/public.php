<?php

declare(strict_types=1);

use App\Plugin\PluginBootContext;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;

return function (App $app, PluginBootContext $ctx): void {
    $app->get('/demo/hello-plugin', function (Request $request, Response $response) use ($ctx): Response {
        $response->getBody()->write(
            '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Hello plugin</title></head><body>'
            . '<p>Public route from <code>hello-plugin</code>.</p>'
            . '<p><a href="' . htmlspecialchars($ctx->viewData()['site_url'] ?? '/', ENT_QUOTES) . '">Home</a></p>'
            . '</body></html>'
        );

        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    })->setName('plugin.hello_plugin.public_demo');
};
