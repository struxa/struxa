<?php

declare(strict_types=1);

use App\Access\PermissionSlug;
use App\Flash;
use App\Http\Middleware\RequireCmsStaff;
use App\Http\Middleware\RequirePermission;
use App\Plugin\PluginBootContext;
use ContentStreamPlugin\KeywordMetricsHandler;
use ContentStreamPlugin\DomainInput;
use ContentStreamPlugin\KeywordPlanGenerateHandler;
use ContentStreamPlugin\KeywordPlanRepository;
use ContentStreamPlugin\SettingsRepository;
use ContentStreamPlugin\StreamToolHandler;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Slim\Routing\RouteContext;

return function (App $app, PluginBootContext $ctx): void {
    $middleware = new RequireCmsStaff($ctx->auth(), $ctx->pdo());
    $permSettings = new RequirePermission($ctx->pdo(), [PermissionSlug::MANAGE_SETTINGS]);
    $twig = $ctx->twig();
    $pdo = $ctx->pdo();

    $app->group('/admin', function (\Slim\Routing\RouteCollectorProxy $group) use ($ctx, $twig, $pdo, $permSettings): void {
        $toolHandler = function (Request $request, Response $response) use ($ctx, $twig, $pdo): Response {
            $out = StreamToolHandler::processRequest($request, $pdo);
            if (strtoupper($request->getMethod()) === 'GET') {
                $qDomain = $request->getQueryParams()['domain'] ?? null;
                if (is_string($qDomain) && trim($qDomain) !== '') {
                    $parsed = DomainInput::parse($qDomain);
                    if ($parsed !== null) {
                        $out['domain'] = $parsed;
                    }
                }
            }
            if (StreamToolHandler::wantsJsonResponse($request)) {
                $response->getBody()->write(json_encode([
                    'domain' => $out['domain'],
                    'brief' => $out['brief'],
                    'analysis' => $out['analysis'],
                    'error' => $out['error'],
                    'table_ok' => $out['table_ok'],
                    'api_configured' => $out['api_configured'],
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

                return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
            }
            $parser = RouteContext::fromRequest($request)->getRouteParser();
            $cmsUser = $request->getAttribute('cms_user') ?? [];
            $streamSettings = (new SettingsRepository($pdo))->get();
            $kpRepo = new KeywordPlanRepository($pdo);

            return $twig->render($response, '@plugin_content_stream_plugin/admin/tool.twig', array_merge($ctx->viewData(), [
                'cms_user' => is_array($cmsUser) ? $cmsUser : [],
                'admin_nav' => 'extensions_plugins',
                'content_stream_domain' => $out['domain'],
                'content_stream_brief' => $out['brief'],
                'content_stream_analysis' => $out['analysis'],
                'content_stream_error' => $out['error'],
                'content_stream_table_ok' => $out['table_ok'],
                'content_stream_api_configured' => $out['api_configured'],
                'content_stream_form_action' => $parser->urlFor('plugin.content_stream_plugin.tool'),
                'content_stream_keyword_metrics_url' => $parser->urlFor('plugin.content_stream_plugin.keyword_metrics'),
                'content_stream_keyword_plan_generate_url' => $parser->urlFor('plugin.content_stream_plugin.tool_keyword_plan_generate'),
                'content_stream_keyword_plan_ready' => $kpRepo->plansTableExists(),
                'content_stream_keyword_plan_default_month' => (new \DateTimeImmutable('now'))->format('Y-m'),
                'content_stream_dataforseo_configured' => $streamSettings['dataforseo_configured'],
            ]));
        };

        $group->get('/content-stream-plugin/tool', $toolHandler)->setName('plugin.content_stream_plugin.tool');
        $group->post('/content-stream-plugin/tool', $toolHandler);

        $group->post('/content-stream-plugin/tool/keyword-metrics', function (Request $request, Response $response) use ($pdo): Response {
            return KeywordMetricsHandler::handle($request, $response, $pdo);
        })->setName('plugin.content_stream_plugin.keyword_metrics');

        $group->post('/content-stream-plugin/tool/keyword-plan-generate', function (Request $request, Response $response) use ($ctx, $pdo, $twig): Response {
            return KeywordPlanGenerateHandler::handle($request, $response, $pdo, $twig, $ctx->viewData());
        })->setName('plugin.content_stream_plugin.tool_keyword_plan_generate');

        $group->group('', function (\Slim\Routing\RouteCollectorProxy $g) use ($ctx, $twig, $pdo): void {
            $g->get('/content-stream-plugin', function (Request $request, Response $response) use ($ctx, $twig, $pdo): Response {
                $repo = new SettingsRepository($pdo);
                $cmsUser = $request->getAttribute('cms_user') ?? [];

                return $twig->render($response, '@plugin_content_stream_plugin/admin/settings.twig', array_merge($ctx->viewData(), [
                    'cms_user' => is_array($cmsUser) ? $cmsUser : [],
                    'admin_nav' => 'extensions_plugins',
                    'content_stream_settings' => $repo->get(),
                    'content_stream_table_ok' => $repo->tableExists(),
                ]));
            })->setName('plugin.content_stream_plugin.admin');

            $g->post('/content-stream-plugin', function (Request $request, Response $response) use ($ctx, $pdo): Response {
                $repo = new SettingsRepository($pdo);
                $parser = RouteContext::fromRequest($request)->getRouteParser();
                $redirect = $response->withHeader('Location', $parser->urlFor('plugin.content_stream_plugin.admin'))->withStatus(302);

                if (!$repo->tableExists()) {
                    return $redirect;
                }

                $parsed = $request->getParsedBody();
                $body = is_array($parsed) ? $parsed : [];

                if (!empty($body['openai_api_key_clear'])) {
                    $repo->clearApiKey();
                }
                if (!empty($body['dataforseo_password_clear'])) {
                    $repo->clearDataForSeoPassword();
                }

                $save = [
                    'openai_organization' => trim((string) ($body['openai_organization'] ?? '')),
                    'openai_model' => trim((string) ($body['openai_model'] ?? 'gpt-4o-mini')) ?: 'gpt-4o-mini',
                    'dataforseo_login' => trim((string) ($body['dataforseo_login'] ?? '')),
                    'keyword_location_code' => (int) ($body['keyword_location_code'] ?? 2840),
                    'keyword_language_code' => trim((string) ($body['keyword_language_code'] ?? 'en')),
                ];
                $newKey = trim((string) ($body['openai_api_key'] ?? ''));
                if ($newKey !== '') {
                    $save['openai_api_key'] = $newKey;
                }
                $newDfsPass = trim((string) ($body['dataforseo_password'] ?? ''));
                if ($newDfsPass !== '') {
                    $save['dataforseo_password'] = $newDfsPass;
                }

                $repo->save($save);
                Flash::set('success', 'Content Stream settings saved.');

                return $redirect;
            });
        })->add($permSettings);
    })->add($middleware);
};
