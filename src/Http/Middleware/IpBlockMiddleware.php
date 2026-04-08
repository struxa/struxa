<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Cache\FileCache;
use App\Http\ClientIp;
use App\Security\IpBlockHitThrottledLogger;
use App\Security\IpBlockMatcher;
use App\Security\IpBlockRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

/**
 * Denies requests whose client IP matches a row in cms_ip_blocks (whole site, including admin).
 * Runs early in the stack; patterns are cached briefly in the internal file cache.
 */
final class IpBlockMiddleware implements MiddlewareInterface
{
    private const CACHE_TTL_SEC = 60;

    public function __construct(
        private readonly IpBlockRepository $repository,
        private readonly FileCache $internalCache,
        private readonly ?IpBlockHitThrottledLogger $hitLogger = null,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $patterns = $this->internalCache->get(IpBlockRepository::CACHE_KEY);
        if (!is_array($patterns)) {
            $patterns = $this->repository->allPatterns();
            $this->internalCache->set(IpBlockRepository::CACHE_KEY, $patterns, self::CACHE_TTL_SEC);
        }
        if ($patterns === []) {
            return $handler->handle($request);
        }
        /** @var list<string> $patterns */
        $ip = ClientIp::fromRequest($request);
        if (!IpBlockMatcher::isBlocked($ip, $patterns)) {
            return $handler->handle($request);
        }

        if ($this->hitLogger !== null) {
            $this->hitLogger->recordBlockedHit($request, $ip);
        }

        $response = new Response(403);
        $response->getBody()->write($this->forbiddenBody());

        return $response
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store');
    }

    private function forbiddenBody(): string
    {
        return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Access denied</title>'
            . '<style>body{font-family:system-ui,sans-serif;max-width:32rem;margin:3rem auto;padding:0 1rem;color:#1e293b;background:#f8fafc}</style></head><body>'
            . '<h1>Access denied</h1><p>Your network is not allowed to access this site.</p></body></html>';
    }
}
