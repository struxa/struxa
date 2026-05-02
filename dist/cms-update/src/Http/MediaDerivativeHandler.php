<?php

declare(strict_types=1);

namespace App\Http;

use App\Media\MediaDerivativeService;
use App\Media\MediaDerivativeWidths;
use App\Media\MediaRepository;
use App\Media\MediaUrlHelper;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * Serves resized library images at GET /media-rs/{width}/{id} (WebP or JPEG, disk-cached).
 */
final class MediaDerivativeHandler
{
    public function __construct(
        private readonly MediaDerivativeService $derivatives,
        private readonly MediaUrlHelper $mediaUrls,
        private readonly MediaRepository $mediaRepo,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $width = (int) ($args['width'] ?? 0);
        $id = (int) ($args['id'] ?? 0);
        if ($id < 1 || !MediaDerivativeWidths::isAllowed($width)) {
            throw new HttpNotFoundException($request);
        }

        if (!$this->mediaRepo->isImageId($id)) {
            throw new HttpNotFoundException($request);
        }

        $path = $this->mediaUrls->pathForId($id);
        if ($path === '') {
            throw new HttpNotFoundException($request);
        }

        $accept = strtolower($request->getHeaderLine('Accept'));
        $preferWebp = str_contains($accept, 'image/webp');

        $out = $this->derivatives->derivativeFile($id, $width, $preferWebp);
        if ($out === null) {
            return $response
                ->withHeader('Location', $path)
                ->withStatus(302)
                ->withHeader('Cache-Control', 'private, no-cache');
        }

        $body = @file_get_contents($out['path']);
        if ($body === false || $body === '') {
            throw new HttpNotFoundException($request);
        }

        $response->getBody()->write($body);

        return $response
            ->withStatus(200)
            ->withHeader('Content-Type', $out['mime'])
            ->withHeader('Cache-Control', 'public, max-age=31536000, immutable');
    }
}
