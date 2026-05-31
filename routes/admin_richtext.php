<?php

declare(strict_types=1);

use App\Http\Middleware\RequireCmsStaff;
use App\Richtext\OEmbedRenderer;
use App\Richtext\OEmbedUrlParser;
use PHPAuth\Auth;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Slim\Views\Twig;

/**
 * @param callable(): array<string, mixed> $viewData
 */
return static function (App $app, Twig $twig, Auth $auth, \PDO $pdo, callable $viewData): void {
    $middleware = new RequireCmsStaff($auth, $pdo);

    $app->get('/admin/richtext/oembed', function (Request $request, Response $response): Response {
        $writeJson = static function (Response $resp, array $payload, int $status): Response {
            $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $resp->getBody()->write($body);

            return $resp
                ->withStatus($status)
                ->withHeader('Content-Type', 'application/json; charset=utf-8');
        };

        $url = trim((string) ($request->getQueryParams()['url'] ?? ''));
        if ($url === '' || strlen($url) > 2048) {
            return $writeJson($response, ['ok' => false, 'error' => 'URL is required.'], 422);
        }

        $match = OEmbedUrlParser::parse($url);
        if ($match === null) {
            return $writeJson($response, ['ok' => false, 'error' => 'Unsupported URL. Use a YouTube or X (Twitter) link.'], 422);
        }

        $html = OEmbedRenderer::render($match);
        if ($html === '') {
            return $writeJson($response, ['ok' => false, 'error' => 'Could not build embed for this URL.'], 422);
        }

        return $writeJson($response, [
            'ok' => true,
            'provider' => $match->provider,
            'html' => $html,
        ], 200);
    })->add($middleware)->setName('admin.richtext.oembed');
};
