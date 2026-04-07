<?php

declare(strict_types=1);

namespace App\Http;

use Psr\Http\Message\ServerRequestInterface;

/**
 * True when the client asked for JSON and did not list HTML as an acceptable type.
 * Avoids treating browser navigations (Accept includes text/html) as API calls.
 */
final class AcceptPrefersJson
{
    public static function withoutHtml(ServerRequestInterface $request): bool
    {
        $accept = strtolower($request->getHeaderLine('Accept'));
        if ($accept === '') {
            return false;
        }

        return str_contains($accept, 'application/json') && !str_contains($accept, 'text/html');
    }
}
