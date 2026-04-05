<?php

declare(strict_types=1);

namespace App\Cache;

use App\Flash;
use App\Security\TwoFactorLoginSession;
use App\Theme\ThemeManager;
use PHPAuth\Auth;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

/**
 * Caches full GET storefront responses for guests only.
 */
final class PublicResponseCacheMiddleware implements MiddlewareInterface
{
    /** Bump when cache key or stored payload shape changes (orphans old files harmlessly). */
    private const CACHE_VERSION = 'v3';

    public function __construct(
        private readonly Auth $auth,
        private readonly FileCache $publicCache,
        private readonly ThemeManager $themeManager,
        private readonly string $authSessionCookieName = 'phpauth_session_cookie',
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!CacheConfig::publicCacheEnabled()) {
            $response = $handler->handle($request);

            return $this->withDebug($response, 'BYPASS', 'cache_disabled');
        }

        $bypassReason = $this->bypassReason($request);
        if ($bypassReason !== null) {
            $response = $handler->handle($request);

            return $this->withDebug($response, 'BYPASS', $bypassReason);
        }

        $method = strtoupper($request->getMethod());
        if ($method !== 'GET') {
            $response = $handler->handle($request);

            return $this->withDebug($response, 'BYPASS', 'method_' . strtolower($method));
        }

        $cacheKey = PublicPageCacheKey::build($request, self::CACHE_VERSION, $this->themeManager->activeSlug());
        $stored = $this->publicCache->get($cacheKey);
        $cached = PublicResponseCacheEnvelope::responsePayload($stored);
        if ($cached !== null) {
            $res = new Response($cached['status']);
            foreach ($cached['headers'] ?? [] as $name => $values) {
                if (!is_string($name) || !is_array($values)) {
                    continue;
                }
                foreach ($values as $v) {
                    if (is_string($v) && $v !== '') {
                        $res = $res->withAddedHeader($name, $v);
                    }
                }
            }
            $res->getBody()->write((string) $cached['body']);

            return $this->withDebug($res, 'HIT', '');
        }

        $response = $handler->handle($request);

        $storeReason = $this->storeBlockReason($request, $response);
        if ($storeReason !== null) {
            return $this->withDebug($response, 'MISS', $storeReason);
        }

        $toStore = $this->serializeResponse($response);
        if ($toStore !== null) {
            $this->publicCache->set(
                $cacheKey,
                PublicResponseCacheEnvelope::wrap($cacheKey, $toStore),
                CacheConfig::publicTtlSeconds()
            );

            return $this->withDebug($response, 'MISS', 'stored', true);
        }

        return $this->withDebug($response, 'MISS', 'serialize_skipped');
    }

    private function withDebug(
        ResponseInterface $response,
        string $state,
        string $detail,
        ?bool $stored = null,
    ): ResponseInterface {
        if (!CacheConfig::sendDebugCacheHeaders()) {
            return $response;
        }
        $response = $response->withHeader('X-Struxa-Page-Cache', $state);
        if ($detail !== '') {
            $response = $response->withHeader('X-Struxa-Page-Cache-Detail', $detail);
        }
        if ($stored !== null) {
            $response = $response->withHeader('X-Struxa-Page-Cache-Stored', $stored ? '1' : '0');
        }

        return $response;
    }

    private function bypassReason(ServerRequestInterface $request): ?string
    {
        if ($this->auth->isLogged()) {
            return 'authenticated';
        }

        if (TwoFactorLoginSession::isPending()) {
            return 'totp_pending';
        }

        if (Flash::hasPending()) {
            return 'flash_pending';
        }

        if ($this->requestHasAuthSessionCookie($request)) {
            return 'auth_session_cookie';
        }

        $path = $request->getUri()->getPath();
        if (str_starts_with($path, '/admin')) {
            return 'admin_path';
        }

        if (preg_match('#^/(login|logout|register)(/|$)#', $path) === 1) {
            return 'auth_route';
        }

        $q = $request->getQueryParams();
        if (array_key_exists('preview', $q)) {
            return 'preview_query';
        }
        if (isset($q['nocache']) && (string) $q['nocache'] !== '' && (string) $q['nocache'] !== '0') {
            return 'nocache_query';
        }
        if (isset($q['struxa_nocache']) && (string) $q['struxa_nocache'] !== '' && (string) $q['struxa_nocache'] !== '0') {
            return 'struxa_nocache_query';
        }

        if (self::isStaticAssetPath($path)) {
            return 'static_asset_path';
        }

        return null;
    }

    private function requestHasAuthSessionCookie(ServerRequestInterface $request): bool
    {
        $line = $request->getHeaderLine('Cookie');
        if ($line === '') {
            return false;
        }
        $name = preg_quote($this->authSessionCookieName, '/');

        return preg_match('/(?:^|;\s*)' . $name . '\s*=/', $line) === 1;
    }

    private function storeBlockReason(ServerRequestInterface $request, ResponseInterface $response): ?string
    {
        if ($response->getStatusCode() !== 200) {
            return 'status_' . (string) $response->getStatusCode();
        }

        foreach ($response->getHeader('Set-Cookie') as $line) {
            if ($line !== '') {
                return 'set_cookie';
            }
        }

        $ct = strtolower($response->getHeaderLine('Content-Type'));
        if ($ct === '') {
            return 'no_content_type';
        }

        $path = PublicPageCacheKey::normalizePath($request->getUri()->getPath());
        $isRobots = strcasecmp($path, '/robots.txt') === 0;

        $allowedHtmlOrXml = str_contains($ct, 'text/html')
            || str_contains($ct, 'application/xml')
            || str_contains($ct, 'text/xml');
        $allowedRobots = $isRobots && str_contains($ct, 'text/plain');

        if (!$allowedHtmlOrXml && !$allowedRobots) {
            return 'content_type';
        }

        $cc = strtolower($response->getHeaderLine('Cache-Control'));
        if (str_contains($cc, 'no-store') || str_contains($cc, 'private')) {
            return 'cache_control';
        }

        return null;
    }

    /**
     * @return array{status: int, headers: array<string, list<string>>, body: string}|null
     */
    private function serializeResponse(ResponseInterface $response): ?array
    {
        $allowed = [
            'Content-Type',
            'Content-Language',
            'X-Robots-Tag',
        ];

        $headers = [];
        foreach ($allowed as $name) {
            $vals = $response->getHeader($name);
            if ($vals !== []) {
                $headers[$name] = $vals;
            }
        }

        $body = $response->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
        }
        $content = $body->getContents();
        if ($body->isSeekable()) {
            $body->rewind();
        }

        $max = CacheConfig::publicCacheMaxBodyBytes();
        if (strlen($content) > $max) {
            return null;
        }

        return [
            'status' => $response->getStatusCode(),
            'headers' => $headers,
            'body' => $content,
        ];
    }

    private static function isStaticAssetPath(string $path): bool
    {
        if (str_starts_with($path, '/theme-assets/')) {
            return true;
        }
        if (str_starts_with($path, '/media-rs/')) {
            return true;
        }
        if (preg_match('#^/(css|js)(/|$)#', $path) === 1) {
            return true;
        }
        if (str_starts_with($path, '/uploads/') || $path === '/uploads') {
            return true;
        }
        if (str_contains($path, '/favicon') || str_contains($path, 'favicon.')) {
            return true;
        }
        if (str_contains($path, 'site.webmanifest')) {
            return true;
        }

        return false;
    }
}
