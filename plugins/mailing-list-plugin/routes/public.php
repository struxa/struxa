<?php

declare(strict_types=1);

use App\Flash;
use App\Plugin\PluginBootContext;
use App\Security\FileRateLimiter;
use MailingListPlugin\ListRepository;
use MailingListPlugin\SubscribeService;
use MailingListPlugin\SubscriberRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Slim\Exception\HttpNotFoundException;

return function (App $app, PluginBootContext $ctx): void {
    $pdo = $ctx->pdo();
    $lists = new ListRepository($pdo);
    $subscribers = new SubscriberRepository($pdo);
    $rate = new FileRateLimiter($ctx->projectRoot() . '/storage/cache/mailing_list_rate');
    $subscribe = new SubscribeService($lists, $subscribers, $rate);
    $twig = $ctx->twig();

    $wantsJson = static function (Request $request): bool {
        $accept = $request->getHeaderLine('Accept');
        if (str_contains($accept, 'application/json')) {
            return true;
        }
        $parsed = $request->getParsedBody();

        return is_array($parsed) && !empty($parsed['ajax']);
    };

    $json = static function (Response $response, array $payload, int $status): Response {
        $response->getBody()->write(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        return $response->withStatus($status)->withHeader('Content-Type', 'application/json; charset=utf-8');
    };

    $app->get('/mailing-list/subscribe/{listSlug:[a-z0-9]+(?:-[a-z0-9]+)*}', function (Request $request, Response $response, array $args) use ($ctx, $twig, $lists): Response {
        $list = $lists->findBySlug((string) ($args['listSlug'] ?? ''));
        if ($list === null || !$list->isActive) {
            throw new HttpNotFoundException($request);
        }

        return $twig->render($response, '@plugin_mailing_list_plugin/public/subscribe.twig', array_merge($ctx->viewData(), [
            'mailing_list' => $list,
            'page_title' => 'Subscribe — ' . $list->name,
        ]));
    })->setName('plugin.mailing_list_plugin.subscribe_page');

    $app->post('/mailing-list/subscribe', function (Request $request, Response $response) use ($subscribe, $wantsJson, $json): Response {
        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];
        $result = $subscribe->handle($request, $body, $wantsJson($request));

        if ($wantsJson($request)) {
            return $json($response, [
                'ok' => $result['ok'],
                'message' => $result['message'],
                'code' => $result['code'] ?? '',
            ], $result['status']);
        }

        $returnTo = isset($body['return_to']) && is_string($body['return_to']) && str_starts_with($body['return_to'], '/') && !str_starts_with($body['return_to'], '//')
            ? $body['return_to']
            : '/mailing-list/subscribe/' . rawurlencode(trim(is_string($body['list'] ?? $body['list_slug'] ?? '') ? (string) ($body['list'] ?? $body['list_slug']) : ''));

        if ($result['ok']) {
            Flash::set('success', $result['message']);
        } else {
            Flash::set('error', $result['message']);
        }

        return $response->withHeader('Location', $returnTo)->withStatus(302);
    })->setName('plugin.mailing_list_plugin.subscribe');
};
