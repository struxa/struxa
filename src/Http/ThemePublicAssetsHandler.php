<?php

declare(strict_types=1);

namespace App\Http;

use App\Asset\SimpleCssMinifier;
use App\Settings;
use App\Theme\ThemeFilesystem;
use App\Theme\ThemeManager;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * Serves files from the active theme's assets/ directory at /theme-assets/{path}.
 */
final class ThemePublicAssetsHandler
{
    private const CSS_MIN_MAX_BYTES = 2_000_000;

    /** Reject theme assets larger than this to avoid loading huge files into memory. */
    private const MAX_ASSET_BYTES = 10_485_760;

    public function __construct(
        private readonly ThemeManager $themes,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $path = (string) ($args['path'] ?? '');
        $segments = ThemeFilesystem::safeRelativePathSegments($path);
        if ($segments === []) {
            throw new HttpNotFoundException($request);
        }

        $slug = $this->themes->activeSlug();
        $base = $this->themes->assetsPathForSlug($slug);
        if ($base === null) {
            throw new HttpNotFoundException($request);
        }
        $baseReal = realpath($base);
        if ($baseReal === false) {
            throw new HttpNotFoundException($request);
        }

        $relative = implode(DIRECTORY_SEPARATOR, $segments);
        $target = realpath($baseReal . DIRECTORY_SEPARATOR . $relative);
        if ($target === false || !is_file($target) || !ThemeFilesystem::pathIsInsideDirectory($target, $baseReal)) {
            throw new HttpNotFoundException($request);
        }

        $size = @filesize($target);
        if ($size === false || $size > self::MAX_ASSET_BYTES) {
            $response->getBody()->write('Payload too large');

            return $response
                ->withStatus(413)
                ->withHeader('Content-Type', 'text/plain; charset=utf-8')
                ->withHeader('Cache-Control', 'no-store');
        }

        $mime = mime_content_type($target);
        if (!is_string($mime) || $mime === '') {
            $mime = 'application/octet-stream';
        }
        $mime = self::normalizeAssetMime($target, $mime);

        $body = (string) file_get_contents($target);

        if ($mime === 'text/css; charset=utf-8' || $mime === 'text/css') {
            $body = $this->maybeMinifyThemeCss($slug, $target, $body);
        }

        $response->getBody()->write($body);

        $hasVersionBust = self::requestHasNumericVersionQuery($request);
        $isCssOrJs = in_array($mime, ['text/css; charset=utf-8', 'text/css', 'application/javascript; charset=utf-8', 'application/javascript', 'text/javascript'], true);

        // theme_asset() appends ?v=filemtime — long immutable cache is safe when the query changes on edit.
        if ($hasVersionBust) {
            $cacheControl = 'public, max-age=31536000, immutable, stale-while-revalidate=604800';
        } elseif ($isCssOrJs) {
            $cacheControl = 'public, max-age=300, must-revalidate';
        } else {
            $cacheControl = 'public, max-age=604800';
        }

        return $response
            ->withHeader('Content-Type', $mime)
            ->withHeader('Cache-Control', $cacheControl);
    }

    private function maybeMinifyThemeCss(string $themeSlug, string $absoluteSource, string $css): string
    {
        if (Settings::get('storefront_css_minify') !== '1') {
            return $css;
        }
        if (strlen($css) > self::CSS_MIN_MAX_BYTES) {
            return $css;
        }
        $mtime = @filemtime($absoluteSource);
        if ($mtime === false) {
            return $css;
        }
        $root = dirname(__DIR__, 2);
        $safeSlug = preg_replace('/[^a-z0-9_-]/i', '_', $themeSlug) ?: 'theme';
        $cacheDir = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'theme-css-min' . DIRECTORY_SEPARATOR . $safeSlug;
        if (!is_dir($cacheDir) && !@mkdir($cacheDir, 0775, true) && !is_dir($cacheDir)) {
            return SimpleCssMinifier::minify($css);
        }
        $key = hash('sha256', $absoluteSource . "\0" . $mtime);
        $cacheFile = $cacheDir . DIRECTORY_SEPARATOR . $key . '.css';
        if (is_file($cacheFile)) {
            $cached = @file_get_contents($cacheFile);
            if ($cached !== false && $cached !== '') {
                return $cached;
            }
        }
        $out = SimpleCssMinifier::minify($css);
        @file_put_contents($cacheFile, $out);

        return $out;
    }

    private static function requestHasNumericVersionQuery(ServerRequestInterface $request): bool
    {
        $query = $request->getUri()->getQuery();
        if ($query === '') {
            return false;
        }
        parse_str($query, $q);

        return isset($q['v']) && is_string($q['v']) && $q['v'] !== '' && ctype_digit($q['v']);
    }

    /**
     * Theme files are served by extension; libmagic sometimes reports text/plain for .css/.js, which
     * prevents browsers from treating linked stylesheets as CSS.
     */
    private static function normalizeAssetMime(string $absolutePath, string $detected): string
    {
        $ext = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION));

        return match ($ext) {
            'css' => 'text/css; charset=utf-8',
            'js', 'mjs' => 'application/javascript; charset=utf-8',
            default => $detected,
        };
    }
}
