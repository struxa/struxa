<?php

declare(strict_types=1);

namespace App\Http;

use App\Access\PermissionService;
use App\CmsUserRepository;
use PDO;
use PHPAuth\Auth;
use Slim\Interfaces\RouteParserInterface;
use Throwable;

/**
 * Picks the URL a user should land on after a successful (or already established) login.
 *
 * Order of precedence:
 *   1. A user-supplied {@code ?next=} path if {@see SafeRedirectPath::afterLogin()} accepts it.
 *   2. {@code admin.dashboard} when the cms user has the {@code access_admin} permission.
 *   3. {@code home}.
 */
final class PostLoginRedirect
{
    public static function target(
        ?string $next,
        int $phpAuthUid,
        RouteParserInterface $routeParser,
        PDO $pdo
    ): string {
        $homeUrl = $routeParser->urlFor('home');
        $safeNext = SafeRedirectPath::afterLogin($next, '');
        if ($safeNext !== '') {
            return $safeNext;
        }

        if ($phpAuthUid > 0 && self::userCanAccessAdmin($pdo, $phpAuthUid)) {
            try {
                return $routeParser->urlFor('admin.dashboard');
            } catch (Throwable $e) {
                return $homeUrl;
            }
        }

        return $homeUrl;
    }

    /**
     * Convenience for "already logged in" guards on auth pages (no separate UID at hand).
     */
    public static function forCurrentUser(
        Auth $auth,
        RouteParserInterface $routeParser,
        PDO $pdo,
        ?string $next = null
    ): string {
        return self::target($next, $auth->getCurrentUID(), $routeParser, $pdo);
    }

    private static function userCanAccessAdmin(PDO $pdo, int $phpAuthUid): bool
    {
        $cmsUser = CmsUserRepository::findByPhpAuthId($pdo, $phpAuthUid);
        if (!is_array($cmsUser)) {
            return false;
        }

        return (new PermissionService())->canAccessAdmin($pdo, (int) $cmsUser['id']);
    }
}
