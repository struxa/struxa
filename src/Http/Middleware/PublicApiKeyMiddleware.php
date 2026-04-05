<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Api\PublicApiAuthContext;
use App\Api\PublicApiKeyRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

/**
 * Protects /api/v1/* when CMS_PUBLIC_API_KEY is set and/or database API keys exist.
 * Accepts Authorization: Bearer <key> or X-Api-Key: <key>.
 * Env key: full secret. DB keys: prefix.secret (prefix matches cms_public_api_keys.prefix).
 * Optional CORS: CMS_PUBLIC_API_CORS_ORIGIN=* or a single origin URL.
 */
final class PublicApiKeyMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly PublicApiKeyRepository $apiKeys)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (strtoupper($request->getMethod()) === 'OPTIONS') {
            return $this->applyCors(new Response(204), $request);
        }

        $envKey = self::expectedEnvKey();
        $hasDbKeys = $this->apiKeys->hasAnyActive();
        if ($envKey === '' && !$hasDbKeys) {
            return $this->applyCors($this->jsonResponse([
                'ok' => false,
                'error' => 'api_not_configured',
                'message' => 'Set CMS_PUBLIC_API_KEY or create an API key under Admin → Tools → API keys.',
            ], 503), $request);
        }

        $provided = self::extractKey($request);
        if ($provided === null || $provided === '') {
            return $this->applyCors($this->jsonResponse([
                'ok' => false,
                'error' => 'unauthorized',
                'message' => 'Invalid or missing API key. Send Authorization: Bearer <key> or X-Api-Key.',
            ], 401), $request);
        }

        $ctx = $this->authenticate($provided, $envKey);
        if ($ctx === null) {
            return $this->applyCors($this->jsonResponse([
                'ok' => false,
                'error' => 'unauthorized',
                'message' => 'Invalid or missing API key. Send Authorization: Bearer <key> or X-Api-Key.',
            ], 401), $request);
        }

        if ($ctx->apiKeyId !== null) {
            $this->apiKeys->touchLastUsed($ctx->apiKeyId);
        }

        return $this->applyCors($handler->handle($request->withAttribute(PublicApiAuthContext::ATTR, $ctx)), $request);
    }

    private function authenticate(string $provided, string $envKey): ?PublicApiAuthContext
    {
        if ($envKey !== '' && hash_equals($envKey, $provided)) {
            return new PublicApiAuthContext('env', null, self::envScopes());
        }

        $dot = strpos($provided, '.');
        if ($dot !== false && $dot > 0) {
            $prefix = substr($provided, 0, $dot);
            $row = $this->apiKeys->findActiveByPrefix($prefix);
            if ($row !== null && password_verify($provided, $row['key_hash'])) {
                $scopes = PublicApiKeyRepository::decodeScopesJson($row['scopes_json']);

                return new PublicApiAuthContext('database', $row['id'], $scopes);
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private static function envScopes(): array
    {
        $raw = $_ENV['CMS_PUBLIC_API_KEY_SCOPES'] ?? getenv('CMS_PUBLIC_API_KEY_SCOPES');
        $s = is_string($raw) ? trim($raw) : '';
        if ($s === '') {
            return ['read', 'read_drafts', 'write'];
        }
        $parts = array_map('trim', explode(',', $s));

        return PublicApiKeyRepository::normalizeScopes($parts) ?: ['read'];
    }

    private static function expectedEnvKey(): string
    {
        $raw = $_ENV['CMS_PUBLIC_API_KEY'] ?? getenv('CMS_PUBLIC_API_KEY');

        return is_string($raw) ? trim($raw) : '';
    }

    private static function extractKey(ServerRequestInterface $request): ?string
    {
        $h = $request->getHeaderLine('X-Api-Key');
        if ($h !== '') {
            return trim($h);
        }
        $auth = $request->getHeaderLine('Authorization');
        if (preg_match('/^\s*Bearer\s+(\S+)\s*$/i', $auth, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function jsonResponse(array $payload, int $status): ResponseInterface
    {
        $r = new Response($status);
        $r->getBody()->write(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $r->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    private function applyCors(ResponseInterface $response, ServerRequestInterface $request): ResponseInterface
    {
        $origin = $_ENV['CMS_PUBLIC_API_CORS_ORIGIN'] ?? getenv('CMS_PUBLIC_API_CORS_ORIGIN');
        $origin = is_string($origin) ? trim($origin) : '';
        if ($origin === '') {
            return $response;
        }
        $allowOrigin = $origin === '*' ? '*' : $origin;
        $response = $response
            ->withHeader('Access-Control-Allow-Origin', $allowOrigin)
            ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PATCH, OPTIONS')
            ->withHeader('Access-Control-Allow-Headers', 'Authorization, X-Api-Key, Content-Type')
            ->withHeader('Access-Control-Max-Age', '86400');
        if ($allowOrigin !== '*') {
            $response = $response->withHeader('Vary', 'Origin');
        }

        return $response;
    }
}
