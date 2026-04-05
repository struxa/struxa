<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Seo\RedirectRepository;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

final class RedirectMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        if ($this->shouldSkip($path)) {
            return $handler->handle($request);
        }

        $repo = new RedirectRepository($this->pdo);
        $norm = RedirectRepository::normalizePath($path);
        $row = $repo->findByPath($norm);
        if ($row === null) {
            return $handler->handle($request);
        }

        $to = trim($row['to_url']);
        if ($to === '') {
            return $handler->handle($request);
        }

        $toPath = parse_url($to, PHP_URL_PATH);
        if (is_string($toPath) && $toPath !== '' && RedirectRepository::normalizePath($toPath) === $norm) {
            return $handler->handle($request);
        }

        $repo->incrementHit((int) $row['id']);
        $code = (int) $row['status_code'];
        if ($code < 300 || $code > 399) {
            $code = 301;
        }

        $response = new Response($code);
        $response = $response->withHeader('Location', $to)->withHeader('Cache-Control', 'public, max-age=300');

        return $response;
    }

    private function shouldSkip(string $path): bool
    {
        $p = $path === '' ? '/' : $path;
        if (str_starts_with($p, '/admin')) {
            return true;
        }
        if (str_starts_with($p, '/theme-assets')) {
            return true;
        }
        if (str_starts_with($p, '/uploads')) {
            return true;
        }
        if ($p === '/sitemap.xml' || $p === '/robots.txt') {
            return true;
        }

        return false;
    }
}
