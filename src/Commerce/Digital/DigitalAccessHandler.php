<?php

declare(strict_types=1);

namespace App\Commerce\Digital;

use App\Commerce\Order\CommerceOrderRepository;
use App\Media\MediaRepository;
use App\Media\MediaStorage;
use App\Media\MediaUrlHelper;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpForbiddenException;
use Slim\Exception\HttpGoneException;
use Slim\Exception\HttpNotFoundException;
use Slim\Psr7\Stream;

final class DigitalAccessHandler
{
    public function __construct(
        private readonly DigitalGrantRepository $grants,
        private readonly CommerceOrderRepository $orders,
        private readonly MediaRepository $media,
        private readonly MediaUrlHelper $mediaUrls,
        private readonly string $projectRoot,
    ) {
    }

    public function serveByToken(Request $request, Response $response, string $token): Response
    {
        $grant = $this->grants->findByToken($token);
        if ($grant === null) {
            throw new HttpNotFoundException($request);
        }

        return $this->serveGrant($request, $response, $grant);
    }

    public function serveByGrantId(Request $request, Response $response, int $orderId, int $grantId): Response
    {
        $grant = $this->grants->findById($grantId);
        if ($grant === null || $grant->orderId !== $orderId) {
            throw new HttpNotFoundException($request);
        }

        return $this->serveGrant($request, $response, $grant);
    }

    private function serveGrant(Request $request, Response $response, DigitalGrant $grant): Response
    {
        if (!$grant->isActive()) {
            throw new HttpGoneException($request, 'This download link has been revoked.');
        }

        $order = $this->orders->findById($grant->orderId);
        if ($order === null || $order->status !== 'paid') {
            throw new HttpForbiddenException($request, 'Order is not eligible for download.');
        }

        $this->grants->recordDownload($grant->id);

        return match ($grant->deliveryType) {
            DigitalDeliverySpec::TYPE_FILE => $this->streamFile($request, $response, $grant),
            DigitalDeliverySpec::TYPE_URL => $this->redirectUrl($response, (string) ($grant->payload['url'] ?? '')),
            DigitalDeliverySpec::TYPE_ENTRY => $this->redirectEntry(
                $request,
                $response,
                (string) ($grant->payload['type_slug'] ?? ''),
                (string) ($grant->payload['entry_slug'] ?? ''),
            ),
            default => throw new HttpNotFoundException($request),
        };
    }

    private function streamFile(Request $request, Response $response, DigitalGrant $grant): Response
    {
        $mediaId = (int) ($grant->payload['media_id'] ?? 0);
        $media = $this->media->findById($mediaId);
        if ($media === null) {
            throw new HttpNotFoundException($request, 'File no longer available.');
        }
        $webPath = $this->mediaUrls->pathForMedia($media);
        if ($webPath === '' || !MediaStorage::isSafeManagedWebPath($webPath)) {
            throw new HttpNotFoundException($request, 'File path is invalid.');
        }
        $absolute = $this->projectRoot . '/public' . $webPath;
        if (!is_file($absolute)) {
            throw new HttpNotFoundException($request, 'File not found on disk.');
        }

        $stream = new Stream(fopen($absolute, 'rb'));
        $filename = $media->originalName !== '' ? $media->originalName : $media->filename;
        $disposition = 'attachment; filename="' . str_replace('"', '', $filename) . '"';

        return $response
            ->withHeader('Content-Type', $media->mimeType !== '' ? $media->mimeType : 'application/octet-stream')
            ->withHeader('Content-Disposition', $disposition)
            ->withHeader('Content-Length', (string) filesize($absolute))
            ->withBody($stream);
    }

    private function redirectUrl(Response $response, string $url): Response
    {
        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return $response->withStatus(404);
        }

        return $response->withHeader('Location', $url)->withStatus(302);
    }

    private function redirectEntry(Request $request, Response $response, string $typeSlug, string $entrySlug): Response
    {
        if ($typeSlug === '' || $entrySlug === '') {
            throw new HttpNotFoundException($request, 'Linked content is unavailable.');
        }

        return $response->withHeader('Location', '/' . rawurlencode($typeSlug) . '/' . rawurlencode($entrySlug))->withStatus(302);
    }
}
