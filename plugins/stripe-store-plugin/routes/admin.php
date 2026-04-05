<?php

declare(strict_types=1);

use App\Access\PermissionSlug;
use App\Http\Middleware\RequireCmsStaff;
use App\Http\Middleware\RequirePermission;
use App\Plugin\PluginBootContext;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use StripeStorePlugin\SettingsRepository;

return function (App $app, PluginBootContext $ctx): void {
    $middleware = new RequireCmsStaff($ctx->auth(), $ctx->pdo());
    $permSettings = new RequirePermission($ctx->pdo(), [PermissionSlug::MANAGE_SETTINGS]);
    $twig = $ctx->twig();
    $pdo = $ctx->pdo();

    $app->group('/admin', function (\Slim\Routing\RouteCollectorProxy $group) use ($ctx, $twig, $pdo, $permSettings): void {
        $group->group('', function (\Slim\Routing\RouteCollectorProxy $g) use ($ctx, $twig, $pdo): void {
            $g->get('/stripe-store-plugin', function (Request $request, Response $response) use ($ctx, $twig, $pdo): Response {
                $repo = new SettingsRepository($pdo);
                $cmsUser = $request->getAttribute('cms_user') ?? [];

                return $twig->render($response, '@plugin_stripe_store_plugin/admin/settings.twig', array_merge($ctx->viewData(), [
                    'cms_user' => $cmsUser,
                    'admin_nav' => 'extensions_plugins',
                    'stripe_store_settings' => $repo->get(),
                    'stripe_store_table_ok' => $repo->tableExists(),
                ]));
            })->setName('plugin.stripe_store_plugin.admin');

            $g->post('/stripe-store-plugin', function (Request $request, Response $response) use ($ctx, $twig, $pdo): Response {
                $repo = new SettingsRepository($pdo);
                if (!$repo->tableExists()) {
                    $cmsUser = $request->getAttribute('cms_user') ?? [];

                    return $twig->render($response->withStatus(503), '@plugin_stripe_store_plugin/admin/settings.twig', array_merge($ctx->viewData(), [
                        'cms_user' => $cmsUser,
                        'admin_nav' => 'extensions_plugins',
                        'stripe_store_settings' => $repo->get(),
                        'stripe_store_table_ok' => false,
                        'stripe_store_error' => 'Run the SQL migration in plugins/stripe-store-plugin/migrations/ first.',
                    ]));
                }

                $parsed = $request->getParsedBody();
                $body = is_array($parsed) ? $parsed : [];

                $repo->save([
                    'publishable_key' => trim((string) ($body['publishable_key'] ?? '')),
                    'secret_key' => trim((string) ($body['secret_key'] ?? '')),
                    'webhook_secret' => trim((string) ($body['webhook_secret'] ?? '')),
                    'allowed_type_slugs' => trim((string) ($body['allowed_type_slugs'] ?? 'products')) ?: 'products',
                    'currency' => strtolower(trim((string) ($body['currency'] ?? 'usd'))) ?: 'usd',
                    'embed_enabled' => isset($body['embed_enabled']) && (string) $body['embed_enabled'] === '1',
                    'button_label' => trim((string) ($body['button_label'] ?? 'Buy now')) ?: 'Buy now',
                ]);

                $cmsUser = $request->getAttribute('cms_user') ?? [];

                return $twig->render($response, '@plugin_stripe_store_plugin/admin/settings.twig', array_merge($ctx->viewData(), [
                    'cms_user' => $cmsUser,
                    'admin_nav' => 'extensions_plugins',
                    'stripe_store_settings' => $repo->get(),
                    'stripe_store_table_ok' => true,
                    'stripe_store_saved' => true,
                ]));
            });
        })->add($permSettings);
    })->add($middleware);
};
