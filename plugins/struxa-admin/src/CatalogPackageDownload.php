<?php

declare(strict_types=1);

namespace StruxaAdmin;

use Psr\Http\Message\ResponseInterface as Response;
use Slim\Psr7\Stream;

final class CatalogPackageDownload
{
    public function __construct(
        private readonly CatalogSettings $settings,
        private readonly CatalogDownloadStatsRepository $stats,
    ) {
    }

    public function trackedDownloadUrl(string $kind, string $slug): string
    {
        return $this->settings->trackedDownloadUrl($kind, $slug);
    }

    public function serve(Response $response, string $kind, string $slug): Response
    {
        $slug = strtolower(trim($slug));
        if (!SubmissionKind::isValid($kind) || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
            return $response->withStatus(404);
        }

        $zipPath = $this->settings->distRoot() . '/zips/' . $slug . '.zip';
        if (!is_file($zipPath) || !is_readable($zipPath)) {
            return $response->withStatus(404);
        }

        $this->stats->recordDownload($kind, $slug);

        $stream = fopen($zipPath, 'rb');
        if ($stream === false) {
            return $response->withStatus(500);
        }

        $body = new Stream($stream);

        return $response
            ->withBody($body)
            ->withHeader('Content-Type', 'application/zip')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $slug . '.zip"')
            ->withHeader('Content-Length', (string) filesize($zipPath))
            ->withHeader('Cache-Control', 'private, no-store');
    }
}
