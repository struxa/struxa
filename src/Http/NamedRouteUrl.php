<?php

declare(strict_types=1);

namespace App\Http;

use InvalidArgumentException;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Slim\Interfaces\RouteParserInterface;
use Slim\Routing\RouteContext;

/**
 * Build named route URLs without throwing when the route was not registered (e.g. plugin admin routes skipped).
 */
final class NamedRouteUrl
{
    /**
     * @param array<string, string> $params
     */
    public static function tryFor(?RouteParserInterface $parser, string $name, array $params = []): ?string
    {
        if ($parser === null) {
            return null;
        }

        try {
            return $parser->urlFor($name, $params);
        } catch (RuntimeException | InvalidArgumentException) {
            return null;
        }
    }

    /**
     * @param array<string, string> $params
     */
    public static function tryFromRequest(ServerRequestInterface $request, string $name, array $params = []): ?string
    {
        return self::tryFor(RouteContext::fromRequest($request)->getRouteParser(), $name, $params);
    }
}
