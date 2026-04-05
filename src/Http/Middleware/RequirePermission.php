<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Access\PermissionService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;
use PDO;

/**
 * Requires cms_user on request and at least one of the given permission slugs.
 */
final class RequirePermission implements MiddlewareInterface
{
    /** @param list<string> $anyOfSlugs */
    public function __construct(
        private readonly PDO $pdo,
        private readonly array $anyOfSlugs,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        /** @var array<string, mixed>|null $cmsUser */
        $cmsUser = $request->getAttribute('cms_user');
        if (!is_array($cmsUser) || !isset($cmsUser['id'])) {
            return $this->forbidden('Not authenticated.');
        }

        $cached = $cmsUser['permission_slugs'] ?? null;
        if (is_array($cached)) {
            $ok = false;
            foreach ($this->anyOfSlugs as $need) {
                if (in_array($need, $cached, true)) {
                    $ok = true;
                    break;
                }
            }
            if (!$ok) {
                return $this->forbidden('You do not have permission for this action.');
            }

            return $handler->handle($request);
        }

        $svc = new PermissionService();
        if (!$svc->userHasAny($this->pdo, (int) $cmsUser['id'], $this->anyOfSlugs)) {
            return $this->forbidden('You do not have permission for this action.');
        }

        return $handler->handle($request);
    }

    private function forbidden(string $message): ResponseInterface
    {
        $response = new Response(403);
        $response->getBody()->write(
            '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Forbidden</title></head><body style="font-family:system-ui;padding:2rem;">'
            . '<h1>403</h1><p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>'
            . '<p><a href="/admin">Admin</a></p></body></html>'
        );

        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
}
