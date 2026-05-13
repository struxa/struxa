<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Security\FileRateLimiter;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

/**
 * Rate limits POST /login, POST /register, and all /api/v1 traffic per client IP.
 */
final class ThrottlingMiddleware implements MiddlewareInterface
{
    private readonly FileRateLimiter $limiter;

    public function __construct(string $projectRoot)
    {
        $dir = $projectRoot . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'rate_limit';
        $this->limiter = new FileRateLimiter($dir);
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        $method = strtoupper($request->getMethod());
        $ip = FileRateLimiter::clientIp($request);

        if ($method === 'POST' && ($path === '/login' || $path === '/login/two-factor')) {
            $max = $this->intEnv('CMS_LOGIN_MAX_ATTEMPTS_PER_QUARTER_HOUR', 40);
            if (!$this->limiter->hit('login', $ip, $max, 900)) {
                return $this->tooMany('Too many sign-in attempts. Try again in a few minutes.');
            }
        }

        if ($method === 'POST' && $path === '/register') {
            $max = $this->intEnv('CMS_REGISTER_MAX_ATTEMPTS_PER_HOUR', 10);
            if (!$this->limiter->hit('register', $ip, $max, 3600)) {
                return $this->tooMany('Too many sign-up attempts. Try again in an hour.');
            }
        }

        if (str_starts_with($path, '/api/v1')) {
            $max = $this->intEnv('CMS_API_RATE_LIMIT_PER_MINUTE', 180);
            if (!$this->limiter->hit('api_v1', $ip, $max, 60)) {
                $r = new Response(429);
                $r->getBody()->write(json_encode([
                    'ok' => false,
                    'error' => 'rate_limited',
                    'message' => 'Too many API requests. Slow down or try again shortly.',
                ], JSON_THROW_ON_ERROR));

                return $r
                    ->withHeader('Content-Type', 'application/json; charset=utf-8')
                    ->withHeader('Retry-After', '60');
            }
        }

        return $handler->handle($request);
    }

    private function intEnv(string $key, int $default): int
    {
        $raw = $_ENV[$key] ?? getenv($key);
        if (!is_string($raw) && !is_numeric($raw)) {
            return $default;
        }
        $v = (int) (is_string($raw) ? trim($raw) : $raw);

        return $v > 0 ? $v : $default;
    }

    private function tooMany(string $message): ResponseInterface
    {
        $r = new Response(429);
        $r->getBody()->write(
            '<!DOCTYPE html><html><head><meta charset="utf-8"><title>429</title></head><body style="font-family:system-ui;padding:2rem;">'
            . '<h1>429 — Too many requests</h1><p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>'
            . '<p><a href="/login">Back to log in</a></p></body></html>'
        );

        return $r->withHeader('Content-Type', 'text/html; charset=utf-8')->withHeader('Retry-After', '900');
    }
}
