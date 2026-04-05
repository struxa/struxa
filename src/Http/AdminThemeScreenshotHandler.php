<?php

declare(strict_types=1);

namespace App\Http;

use App\Theme\ThemeManager;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * Serves the theme.json screenshot for a theme (staff-only route wrapper).
 */
final class AdminThemeScreenshotHandler
{
    public function __construct(
        private readonly ThemeManager $themes,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $slug = strtolower(trim((string) ($args['slug'] ?? '')));
        if ($slug === '') {
            throw new HttpNotFoundException($request);
        }

        $manifest = $this->themes->findBySlug($slug);
        if ($manifest === null) {
            throw new HttpNotFoundException($request);
        }

        $file = $this->themes->screenshotAbsolutePath($manifest);
        if ($file === null) {
            throw new HttpNotFoundException($request);
        }

        $mime = mime_content_type($file);
        if (!is_string($mime) || $mime === '') {
            $mime = 'application/octet-stream';
        }

        $body = (string) file_get_contents($file);
        $response->getBody()->write($body);

        return $response
            ->withHeader('Content-Type', $mime)
            ->withHeader('Cache-Control', 'private, max-age=3600');
    }
}
