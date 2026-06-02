<?php

declare(strict_types=1);

namespace App\Access;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;
use Slim\Routing\RouteContext;
use Slim\Views\Twig;

final class MemberAccessGate
{
    /**
     * @param list<int> $requiredRoleIds
     * @param callable(): array<string, mixed> $viewData
     */
    public static function enforce(
        ServerRequestInterface $request,
        ResponseInterface $response,
        Twig $twig,
        callable $viewData,
        MemberAccessService $access,
        bool $membersOnly,
        array $requiredRoleIds,
        string $returnPath,
        string $title,
    ): ?ResponseInterface {
        if (!$membersOnly) {
            return null;
        }

        $vd = $viewData();
        $phpauthUserId = isset($vd['phpauth_user_id']) && is_int($vd['phpauth_user_id']) && $vd['phpauth_user_id'] > 0
            ? $vd['phpauth_user_id']
            : null;
        $canAdmin = !empty($vd['user_can_access_admin']);

        if ($access->canView($membersOnly, $requiredRoleIds, $phpauthUserId, $canAdmin)) {
            return null;
        }

        $parser = RouteContext::fromRequest($request)->getRouteParser();

        if ($phpauthUserId === null) {
            $loginUrl = $parser->urlFor('login') . '?' . http_build_query(['next' => $returnPath]);

            return $response->withHeader('Location', $loginUrl)->withStatus(302);
        }

        return $twig->render($response->withStatus(403), 'pages/members_only.twig', array_merge($vd, [
            'members_only_title' => $title,
            'members_only_return' => $returnPath,
        ]));
    }
}
