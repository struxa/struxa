<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Access\MemberAccess;
use App\Access\MemberAccessPolicy;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;
use Slim\Views\Twig;

/**
 * Blocks unauthenticated visitors or members without required roles on public routes.
 *
 * Attach to plugin (or core) routes: {@code ->add($middleware)}.
 */
final class RequireMemberAccess implements MiddlewareInterface
{
    /**
     * @param callable(): array<string, mixed> $viewData
     */
    public function __construct(
        private readonly PDO $pdo,
        private readonly Twig $twig,
        private readonly mixed $viewData,
        private readonly MemberAccessPolicy $policy,
        private readonly ?string $title = null,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$this->policy->membersOnly) {
            return $handler->handle($request);
        }

        $path = $request->getUri()->getPath();
        $query = $request->getUri()->getQuery();
        $returnPath = $path . ($query !== '' ? '?' . $query : '');

        $denied = (new MemberAccess($this->pdo))->require(
            $request,
            new Response(),
            $this->twig,
            $this->viewData,
            $this->policy,
            $returnPath,
            $this->title,
        );

        if ($denied !== null) {
            return $denied;
        }

        return $handler->handle($request);
    }
}
