<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Flash;
use App\Security\CsrfToken;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

/**
 * Validates CSRF token on mutating requests to /admin, auth forms, and registration.
 * Runs after body parsing so multipart and JSON bodies are available.
 */
final class CsrfProtectionMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $method = strtoupper($request->getMethod());
        if (!in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return $handler->handle($request);
        }

        $path = $request->getUri()->getPath();
        if (str_starts_with($path, '/api/v1')) {
            return $handler->handle($request);
        }

        if ($path === '/stripe-store/webhook' || str_starts_with($path, '/stripe-store/webhook')) {
            return $handler->handle($request);
        }

        if ($path === '/commerce/stripe/webhook' || str_starts_with($path, '/commerce/stripe/webhook')) {
            return $handler->handle($request);
        }

        if ($path === '/stripe-store/checkout') {
            return $handler->handle($request);
        }

        // External-link click tracker beacon: fire-and-forget, no session-tied state.
        if ($path === '/track/external-link') {
            return $handler->handle($request);
        }

        if (!$this->pathRequiresCsrf($path)) {
            return $handler->handle($request);
        }

        $token = $this->readSubmittedToken($request);
        if (!CsrfToken::validate($token)) {
            return $this->forbiddenResponse($request);
        }

        return $handler->handle($request);
    }

    private function pathRequiresCsrf(string $path): bool
    {
        if (str_starts_with($path, '/admin')) {
            return true;
        }

        if ($path === '/login' || $path === '/login/two-factor') {
            return true;
        }

        if ($path === '/register' || $path === '/logout') {
            return true;
        }

        if ($path === '/content-stream') {
            return true;
        }

        if ($path === '/plugins/submit' || $path === '/themes/submit') {
            return true;
        }
        if ($path === '/comments/post' || $path === '/comments/like') {
            return true;
        }

        if (preg_match('#^/forms/[a-z0-9]+(?:-[a-z0-9]+)*/submit$#', $path) === 1) {
            return true;
        }

        if ($path === '/commerce/checkout') {
            return true;
        }

        if (in_array($path, ['/commerce/cart/add', '/commerce/cart/update', '/commerce/cart/checkout', '/commerce/cart/coupon'], true)) {
            return true;
        }

        if ($path === '/commerce/orders/lookup') {
            return true;
        }

        return false;
    }

    private function readSubmittedToken(ServerRequestInterface $request): ?string
    {
        $body = $request->getParsedBody();
        if (is_array($body)) {
            if (isset($body['_csrf_token']) && is_string($body['_csrf_token']) && $body['_csrf_token'] !== '') {
                return $body['_csrf_token'];
            }
            if (isset($body['csrf_token']) && is_string($body['csrf_token']) && $body['csrf_token'] !== '') {
                return $body['csrf_token'];
            }
        }

        $h = trim($request->getHeaderLine('X-CSRF-Token'));
        if ($h !== '') {
            return $h;
        }

        return null;
    }

    private function forbiddenResponse(ServerRequestInterface $request): ResponseInterface
    {
        $accept = $request->getHeaderLine('Accept');
        if (str_contains($accept, 'application/json')) {
            $r = new Response(403);
            $r->getBody()->write(json_encode([
                'ok' => false,
                'error' => 'csrf_failed',
                'message' => 'Invalid or missing CSRF token. Refresh the page and try again.',
            ], JSON_THROW_ON_ERROR));

            return $r->withHeader('Content-Type', 'application/json; charset=utf-8');
        }

        Flash::set(
            'error',
            'This page was out of date (often after clearing cache or cookies). Refresh the page, then try again.'
        );
        $target = $this->safeHtmlCsrfRecoveryTarget($request);

        return (new Response(302))
            ->withHeader('Location', $target);
    }

    /**
     * Same-host path only — avoids open redirects while sending users back to a fresh form.
     * Prefer Referer; if missing (e.g. Referrer-Policy), fall back to the POST path for /admin and auth routes.
     */
    private function safeHtmlCsrfRecoveryTarget(ServerRequestInterface $request): string
    {
        $fromReferer = $this->sameHostAllowedPathFromUrl(
            $request,
            $request->getHeaderLine('Referer')
        );
        if ($fromReferer !== null) {
            return $fromReferer;
        }

        $reqPath = $request->getUri()->getPath();
        if ($reqPath === '') {
            $reqPath = '/';
        }
        if (str_starts_with($reqPath, '/admin')
            || in_array($reqPath, ['/login', '/login/two-factor', '/register', '/logout', '/content-stream', '/comments/post', '/comments/like'], true)) {
            return $reqPath;
        }

        return '/';
    }

    private function sameHostAllowedPathFromUrl(ServerRequestInterface $request, string $url): ?string
    {
        if ($url === '') {
            return null;
        }
        /** @var array{host?: string, path?: string, query?: string}|false $parts */
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            return null;
        }
        if (strcasecmp((string) $parts['host'], $request->getUri()->getHost()) !== 0) {
            return null;
        }
        $path = isset($parts['path']) && is_string($parts['path']) ? $parts['path'] : '/';
        if ($path === '') {
            $path = '/';
        }
        $allowed = str_starts_with($path, '/admin')
            || $path === '/login'
            || $path === '/login/two-factor'
            || $path === '/register'
            || $path === '/logout'
            || $path === '/content-stream'
            || $path === '/comments/post'
            || $path === '/comments/like';
        if (!$allowed) {
            return null;
        }
        $q = isset($parts['query']) && is_string($parts['query']) && $parts['query'] !== ''
            ? '?' . $parts['query']
            : '';

        return $path . $q;
    }
}
