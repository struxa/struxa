<?php

declare(strict_types=1);

use App\Analytics\ShortLinkConfig;
use App\Analytics\ShortLinkRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Slim\Exception\HttpNotFoundException;

return static function (App $app, \PDO $pdo): void {
    $redirect = static function (Request $request, Response $response, string $code) use ($pdo): Response {
        if (!ShortLinkConfig::enabled()) {
            throw new HttpNotFoundException($request);
        }

        $repo = new ShortLinkRepository($pdo);
        $link = $repo->findByCode($code);
        if ($link === null) {
            throw new HttpNotFoundException($request);
        }

        $repo->recordClick($link->id);

        return $response
            ->withHeader('Location', $link->destinationUrl)
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('Referrer-Policy', 'no-referrer-when-downgrade')
            ->withStatus(302);
    };

    $prefix = ShortLinkConfig::prefixSegment();
    if ($prefix !== '') {
        $pattern = '/^' . preg_quote($prefix, '#') . '$/';
        if (preg_match($pattern, $prefix) === 1) {
            $app->get('/' . $prefix . '/{code:[a-z0-9]{4,12}}', function (
                Request $request,
                Response $response,
                array $args
            ) use ($redirect): Response {
                return $redirect($request, $response, (string) ($args['code'] ?? ''));
            })->setName('public.short_link.redirect');
        }
    }
};
