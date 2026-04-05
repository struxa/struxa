<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Access\PermissionService;
use App\Access\PermissionSlug;
use App\Access\RoleUserRepository;
use App\CmsUserRepository;
use PHPAuth\Auth;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

/**
 * Requires PHPAuth session, active cms_users row, and access_admin permission (via roles).
 */
final class RequireCmsStaff implements MiddlewareInterface
{
    public function __construct(
        private readonly Auth $auth,
        private readonly PDO $pdo
    ) {
    }

    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler
    ): ResponseInterface {
        if (!CmsUserRepository::tableExists($this->pdo)) {
            return $this->forbidden('CMS tables are not ready. Run: php bin/migrate.php');
        }

        if (!$this->auth->isLogged()) {
            $path = $request->getUri()->getPath();
            $qs = http_build_query(['next' => $path]);

            return (new Response(302))->withHeader('Location', '/login?' . $qs);
        }

        $uid = (int) $this->auth->getCurrentUID();
        if ($uid === 0) {
            // PHPAuth can renew the session hash mid-request; a stale isAuthenticated flag may leave UID unreadable once.
            $this->auth->isAuthenticated = false;
            if ($this->auth->isLogged()) {
                $uid = (int) $this->auth->getCurrentUID();
            }
        }

        if ($uid === 0) {
            $path = $request->getUri()->getPath();
            $qs = http_build_query(['next' => $path]);

            return (new Response(302))->withHeader('Location', '/login?' . $qs);
        }

        $cmsUser = CmsUserRepository::findByPhpAuthId($this->pdo, $uid);

        if ($cmsUser === null) {
            return $this->forbidden('You do not have access to the admin area.');
        }

        if ((int) ($cmsUser['is_active'] ?? 1) !== 1) {
            return $this->forbidden('Your CMS account is deactivated.');
        }

        $perms = new PermissionService();
        if (!$perms->canAccessAdmin($this->pdo, (int) $cmsUser['id'])) {
            return $this->forbidden('You do not have access to the admin area.');
        }

        $slugs = $perms->permissionSlugsForUser($this->pdo, (int) $cmsUser['id']);
        $cmsUser['permission_slugs'] = $slugs;
        $cmsUser['can_manage_roles'] = in_array(PermissionSlug::MANAGE_ROLES, $slugs, true);

        $roleRows = (new RoleUserRepository($this->pdo))->rolesForUser((int) $cmsUser['id']);
        $labels = [];
        foreach ($roleRows as $r) {
            $n = trim((string) ($r['name'] ?? ''));
            $labels[] = $n !== '' ? $n : (string) ($r['slug'] ?? '');
        }
        $cmsUser['role_labels'] = $labels;

        $request = $request->withAttribute('cms_user', $cmsUser);

        return $handler->handle($request);
    }

    private function forbidden(string $message): ResponseInterface
    {
        $response = new Response(403);
        $response->getBody()->write(
            '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Forbidden</title></head><body style="font-family:system-ui;padding:2rem;">'
            . '<h1>403</h1><p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>'
            . '<p><a href="/">Home</a></p></body></html>'
        );

        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
}
