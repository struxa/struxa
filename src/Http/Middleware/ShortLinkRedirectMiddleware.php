<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Analytics\ShortLinkConfig;
use App\Analytics\ShortLinkRepository;
use App\Content\ReservedContentSlugs;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

/**
 * Resolves root short URLs like /dsf6sh when root mode is enabled.
 */
final class ShortLinkRedirectMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly PDO $pdo,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$this->shouldAttempt($request)) {
            return $handler->handle($request);
        }

        $path = $request->getUri()->getPath();
        if (!preg_match('#^/([a-z0-9]{4,12})$#', $path, $matches)) {
            return $handler->handle($request);
        }

        $code = $matches[1];
        if (ReservedContentSlugs::isReserved($code)) {
            return $handler->handle($request);
        }

        $repo = new ShortLinkRepository($this->pdo);
        $link = $repo->findByCode($code);
        if ($link === null) {
            return $handler->handle($request);
        }

        $repo->recordClick($link->id);

        return (new Response(302))
            ->withHeader('Location', $link->destinationUrl)
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('Referrer-Policy', 'no-referrer-when-downgrade');
    }

    private function shouldAttempt(ServerRequestInterface $request): bool
    {
        if (strtoupper($request->getMethod()) !== 'GET') {
            return false;
        }
        if (!ShortLinkConfig::enabled() || !ShortLinkConfig::rootModeEnabled()) {
            return false;
        }

        return true;
    }
}
