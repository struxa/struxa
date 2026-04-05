<?php

declare(strict_types=1);

use App\Http\Middleware\RequireCmsStaff;
use App\Plugin\PluginBootContext;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;

return function (App $app, PluginBootContext $ctx): void {
    $middleware = new RequireCmsStaff($ctx->auth(), $ctx->pdo());
    $twig = $ctx->twig();

    $app->group('/admin', function (\Slim\Routing\RouteCollectorProxy $group) use ($ctx, $twig): void {
        $group->get('/seo-helper-plugin', function (Request $request, Response $response) use ($ctx, $twig): Response {
            /** @var array<string, mixed> $cmsUser */
            $cmsUser = $request->getAttribute('cms_user') ?? [];

            return $twig->render($response, '@plugin_seo_helper_plugin/admin/seo.twig', array_merge($ctx->viewData(), [
                'cms_user' => $cmsUser,
                'admin_nav' => 'extensions_plugins',
            ]));
        })->setName('plugin.seo_helper_plugin.admin');
    })->add($middleware);
};
