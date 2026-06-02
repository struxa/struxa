<?php

declare(strict_types=1);

namespace App\Access;

use App\Http\Middleware\RequireMemberAccess;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;

/**
 * Plugin-friendly entry point for members-only public routes.
 *
 * Example (plugin routes/public.php):
 *
 *   $access = $ctx->memberAccess();
 *   $requireLogin = $access->middleware($ctx->twig(), fn () => $ctx->viewData(), MemberAccessPolicy::loggedIn(), 'Submit a plugin');
 *   $app->get('/plugins/submit', $handler)->add($requireLogin);
 */
final class MemberAccess
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    private function service(): MemberAccessService
    {
        return new MemberAccessService(
            $this->pdo,
            new MemberAccessRepository($this->pdo),
            new RoleUserRepository($this->pdo),
        );
    }

    /**
     * @param callable(): array<string, mixed> $viewData
     */
    public function require(
        ServerRequestInterface $request,
        ResponseInterface $response,
        Twig $twig,
        callable $viewData,
        MemberAccessPolicy $policy,
        string $returnPath,
        ?string $title = null,
    ): ?ResponseInterface {
        if (!$policy->membersOnly) {
            return null;
        }

        return MemberAccessGate::enforce(
            $request,
            $response,
            $twig,
            $viewData,
            $this->service(),
            true,
            $policy->roleIds,
            $returnPath,
            $title ?? 'This page',
        );
    }

    /**
     * Slim middleware for plugin routes.
     *
     * @param callable(): array<string, mixed> $viewData
     */
    public function middleware(
        Twig $twig,
        callable $viewData,
        MemberAccessPolicy $policy,
        ?string $title = null,
    ): RequireMemberAccess {
        return new RequireMemberAccess($this->pdo, $twig, $viewData, $policy, $title);
    }
}
