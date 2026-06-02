<?php

declare(strict_types=1);

namespace App\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;
use Slim\Interfaces\ErrorHandlerInterface;
use Slim\Psr7\Response;
use Slim\Views\Twig;
use Throwable;

/**
 * Friendly HTML (or JSON for API-style requests) for missing routes instead of Slim’s diagnostic page.
 */
final class PublicNotFoundHandler implements ErrorHandlerInterface
{
    /**
     * @param callable(array<string, mixed>=): array<string, mixed> $viewData
     */
    public function __construct(
        private readonly Twig $twig,
        private readonly mixed $viewData,
    ) {
    }

    public function __invoke(
        ServerRequestInterface $request,
        Throwable $exception,
        bool $displayErrorDetails,
        bool $logErrors,
        bool $logErrorDetails,
    ): ResponseInterface {
        if (!$exception instanceof HttpNotFoundException) {
            $fallback = new Response(500);
            $fallback->getBody()->write('Server error.');

            return $fallback->withHeader('Content-Type', 'text/plain; charset=utf-8');
        }

        if ($logErrors) {
            $path = $request->getUri()->getPath();
            $msg = '404 ' . $request->getMethod() . ' ' . $path;
            if ($logErrorDetails) {
                $msg .= ' — ' . $exception->getMessage();
            }
            error_log($msg);
        }

        if ($this->wantsJson($request)) {
            $response = new Response(404);
            $response = $response->withHeader('Content-Type', 'application/json; charset=utf-8');
            $payload = json_encode([
                'error' => 'Not found.',
                'code' => 404,
            ], JSON_THROW_ON_ERROR);
            $response->getBody()->write($payload);

            return $response;
        }

        $response = new Response(404);
        $debugDetail = null;
        if ($displayErrorDetails) {
            $debugDetail = $exception->getMessage() . "\n" . $exception->getFile() . ':' . $exception->getLine();
        }

        return $this->twig->render($response, 'errors/not_found.twig', ($this->viewData)([
            'not_found_debug' => $displayErrorDetails,
            'not_found_debug_detail' => $debugDetail,
        ]));
    }

    private function wantsJson(ServerRequestInterface $request): bool
    {
        $path = $request->getUri()->getPath();
        if (str_starts_with($path, '/api/')) {
            return true;
        }

        $accept = strtolower($request->getHeaderLine('Accept'));
        if ($accept === '') {
            return false;
        }

        return str_contains($accept, 'application/json') && !str_contains($accept, 'text/html');
    }
}
