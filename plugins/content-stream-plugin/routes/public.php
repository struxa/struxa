<?php

declare(strict_types=1);

use App\Http\Middleware\RequireCmsStaff;
use App\Plugin\PluginBootContext;
use ContentStreamPlugin\KeywordPlanRepository;
use ContentStreamPlugin\SettingsRepository;
use ContentStreamPlugin\StreamToolHandler;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Slim\Routing\RouteContext;

return function (App $app, PluginBootContext $ctx): void {
    $pdo = $ctx->pdo();
    $twig = $ctx->twig();
    $staffOnly = new RequireCmsStaff($ctx->auth(), $pdo);

    $handler = function (Request $request, Response $response) use ($ctx, $twig, $pdo): Response {
        $out = StreamToolHandler::processRequest($request, $pdo);
        $resultHtml = '';
        if (is_array($out['analysis']) && $out['analysis'] !== []) {
            $resultHtml = $twig->fetch('@plugin_content_stream_plugin/public/partials/analysis_result_html.twig', array_merge($ctx->viewData(), [
                'analysis' => $out['analysis'],
                'analysis_raw' => $out['brief'],
            ]));
        }
        if (StreamToolHandler::wantsJsonResponse($request)) {
            $response->getBody()->write(json_encode([
                'domain' => $out['domain'],
                'brief' => $out['brief'],
                'analysis' => $out['analysis'],
                'result_html' => $resultHtml,
                'error' => $out['error'],
                'table_ok' => $out['table_ok'],
                'api_configured' => $out['api_configured'],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

            return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
        }
        $parser = RouteContext::fromRequest($request)->getRouteParser();
        $streamSettings = (new SettingsRepository($pdo))->get();
        $kpRepo = new KeywordPlanRepository($pdo);

        return $twig->render($response, '@plugin_content_stream_plugin/public/stream.twig', array_merge($ctx->viewData(), [
            'content_stream_domain' => $out['domain'],
            'content_stream_brief' => $out['brief'],
            'content_stream_analysis' => $out['analysis'],
            'content_stream_error' => $out['error'],
            'content_stream_table_ok' => $out['table_ok'],
            'content_stream_api_configured' => $out['api_configured'],
            'content_stream_form_action' => $parser->urlFor('plugin.content_stream_plugin.public'),
            'content_stream_keyword_metrics_url' => $parser->urlFor('plugin.content_stream_plugin.keyword_metrics'),
            'content_stream_keyword_plan_generate_url' => $parser->urlFor('plugin.content_stream_plugin.tool_keyword_plan_generate'),
            'content_stream_keyword_plan_ready' => $kpRepo->plansTableExists(),
            'content_stream_keyword_plan_default_month' => (new \DateTimeImmutable('now'))->format('Y-m'),
            'content_stream_dataforseo_configured' => $streamSettings['dataforseo_configured'],
        ]));
    };

    $app->get('/content-stream', $handler)
        ->setName('plugin.content_stream_plugin.public')
        ->add($staffOnly);
    $app->post('/content-stream', $handler)->add($staffOnly);
};
