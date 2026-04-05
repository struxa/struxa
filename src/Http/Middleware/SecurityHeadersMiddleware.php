<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Adds baseline security headers to every response.
 */
final class SecurityHeadersMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);
        $path = $request->getUri()->getPath();

        $response = $response
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->withHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        // Avoid serving stale HTML with embedded CSRF tokens after cache/cookie clears or session rotation.
        if (str_starts_with($path, '/admin')
            || $path === '/login'
            || $path === '/login/two-factor'
            || $path === '/register'
            || $path === '/logout') {
            $response = $response
                ->withHeader('Cache-Control', 'private, no-store, must-revalidate')
                ->withHeader('Pragma', 'no-cache');
        }

        if (str_starts_with($path, '/admin')) {
            // TinyMCE (page/entry editors) loads JS/CSS/fonts from jsDelivr; without this, the script is blocked and only a plain textarea appears.
            $jsdelivr = 'https://cdn.jsdelivr.net';
            $response = $response
                ->withHeader('X-Frame-Options', 'DENY')
                ->withHeader('Content-Security-Policy', "default-src 'self'; " .
                    "script-src 'self' 'unsafe-inline' https://fonts.googleapis.com " . $jsdelivr . '; ' .
                    "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com " . $jsdelivr . '; ' .
                    "font-src 'self' https://fonts.gstatic.com " . $jsdelivr . ' data:; ' .
                    "img-src 'self' data: https: blob:; " .
                    "connect-src 'self' " . $jsdelivr . '; ' .
                    "frame-src 'self' blob: data:; " .
                    "frame-ancestors 'none'; " .
                    "base-uri 'self'; " .
                    "form-action 'self'");
        } else {
            $response = $response->withHeader('X-Frame-Options', 'SAMEORIGIN');
            if (self::publicCspEnabled()) {
                $response = $response->withHeader('Content-Security-Policy', self::publicContentSecurityPolicy());
            }
        }

        return $response;
    }

    private static function publicCspEnabled(): bool
    {
        $raw = $_ENV['CMS_PUBLIC_CSP'] ?? getenv('CMS_PUBLIC_CSP');
        if ($raw === false || $raw === null) {
            return true;
        }
        $s = is_string($raw) ? strtolower(trim($raw)) : '';

        return !in_array($s, ['0', 'off', 'false', 'no'], true);
    }

    /**
     * Baseline CSP for the storefront; allows common fonts, images, embeds, and inline scripts used by themes.
     */
    private static function publicContentSecurityPolicy(): string
    {
        // Themes (e.g. cashback shell) may load Tabler from jsDelivr; icon webfonts need font-src.
        $jsdelivr = ' https://cdn.jsdelivr.net';
        return implode(' ', [
            "default-src 'self';",
            'script-src \'self\' \'unsafe-inline\' https://fonts.googleapis.com' . $jsdelivr
                . self::cspDirectiveTokensSuffix('CMS_PUBLIC_CSP_SCRIPT_SRC_EXTRA') . ';',
            "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com" . $jsdelivr . ';',
            "font-src 'self' https://fonts.gstatic.com https://cdn.jsdelivr.net data:;",
            "img-src 'self' data: https: blob:;",
            'connect-src \'self\' https:'
                . self::cspDirectiveTokensSuffix('CMS_PUBLIC_CSP_CONNECT_SRC_EXTRA') . ';',
            'frame-src \'self\' https:'
                . self::cspDirectiveTokensSuffix('CMS_PUBLIC_CSP_FRAME_SRC_EXTRA') . ';',
            "frame-ancestors 'self';",
            "base-uri 'self';",
            "form-action 'self';",
            "object-src 'none';",
        ]);
    }

    /**
     * Appends env-listed CSP source tokens (comma or whitespace separated). Skips tokens containing ; or newlines.
     */
    private static function cspDirectiveTokensSuffix(string $envKey): string
    {
        $raw = $_ENV[$envKey] ?? getenv($envKey);
        if ($raw === false || $raw === null) {
            return '';
        }
        $s = trim((string) $raw);
        if ($s === '') {
            return '';
        }
        $tokens = preg_split('/[\s,]+/', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $out = [];
        foreach ($tokens as $t) {
            $t = trim($t);
            if ($t === '' || strlen($t) > 256) {
                continue;
            }
            if (preg_match('/[\r\n;<>]/', $t) === 1) {
                continue;
            }
            $out[] = $t;
        }
        if ($out === []) {
            return '';
        }

        return ' ' . implode(' ', $out);
    }
}
