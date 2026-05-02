<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Seo\NotFoundLogRepository;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * Records 404 responses for the admin 404 monitor (best-effort, never throws).
 *
 * Slim routes usually signal missing resources with {@see HttpNotFoundException}, which is caught by
 * {@see \Slim\Middleware\ErrorMiddleware} before a 404 response exists. This middleware logs both
 * returned 404 responses and rethrown 404 exceptions so the 404 monitor stays accurate.
 */
final class NotFoundLogMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            $response = $handler->handle($request);
        } catch (HttpNotFoundException $e) {
            $this->recordPublicNotFound($request);

            throw $e;
        }

        if ($response->getStatusCode() === 404) {
            $this->recordPublicNotFound($request);
        }

        return $response;
    }

    private function recordPublicNotFound(ServerRequestInterface $request): void
    {
        $path = $request->getUri()->getPath();
        if (str_starts_with($path, '/admin')) {
            return;
        }

        try {
            $ref = $request->getHeaderLine('Referer');
            $ref = $ref !== '' ? $ref : null;
            (new NotFoundLogRepository($this->pdo))->record($path, $ref);
        } catch (\Throwable) {
        }
    }
}
