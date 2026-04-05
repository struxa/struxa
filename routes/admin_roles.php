<?php

declare(strict_types=1);

use App\Access\ActivityLogger;
use App\Access\PermissionSlug;
use App\Flash;
use App\Http\Middleware\RequireCmsStaff;
use App\Http\Middleware\RequirePermission;
use App\Access\RoleRepository;
use PHPAuth\Auth;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Slim\Exception\HttpNotFoundException;
use Slim\Routing\RouteContext;
use Slim\Views\Twig;

/**
 * @param callable(): array<string, mixed> $viewData
 */
return static function (App $app, Twig $twig, Auth $auth, \PDO $pdo, callable $viewData): void {
    $middleware = new RequireCmsStaff($auth, $pdo);
    $perm = new RequirePermission($pdo, [PermissionSlug::MANAGE_ROLES]);
    $roles = new RoleRepository($pdo);
    $activity = new ActivityLogger($pdo);

    $adminContext = static fn (): array => array_merge($viewData(), []);
    $withCmsUser = static function (Request $request, array $data): array {
        /** @var array<string, mixed> $cmsUser */
        $cmsUser = $request->getAttribute('cms_user') ?? [];

        return array_merge($data, ['cms_user' => $cmsUser]);
    };

    $cmsUid = static function (Request $request): ?int {
        /** @var array<string, mixed> $u */
        $u = $request->getAttribute('cms_user') ?? [];
        $id = isset($u['id']) ? (int) $u['id'] : 0;

        return $id > 0 ? $id : null;
    };

    $app->group('/admin', function (\Slim\Routing\RouteCollectorProxy $group) use ($twig, $adminContext, $withCmsUser, $roles, $activity, $cmsUid): void {
        $group->get('/roles', function (Request $request, Response $response) use ($twig, $adminContext, $withCmsUser, $roles): Response {
            return $twig->render($response, 'admin/roles/index.twig', $withCmsUser($request, array_merge($adminContext(), [
                'admin_nav' => 'roles',
                'role_rows' => $roles->allOrdered(),
            ])));
        })->setName('admin.roles.index');

        $group->get('/roles/new', function (Request $request, Response $response) use ($twig, $adminContext, $withCmsUser, $roles): Response {
            return $twig->render($response, 'admin/roles/form.twig', $withCmsUser($request, array_merge($adminContext(), [
                'admin_nav' => 'roles',
                'form_mode' => 'create',
                'role' => null,
                'all_permissions' => $roles->allPermissions(),
                'selected_perm_ids' => [],
                'errors' => [],
                'old' => ['name' => '', 'slug' => '', 'description' => ''],
            ])));
        })->setName('admin.roles.new');

        $group->post('/roles/new', function (Request $request, Response $response) use ($twig, $adminContext, $withCmsUser, $roles, $activity, $cmsUid): Response {
            $body = $request->getParsedBody();
            $body = is_array($body) ? $body : [];
            $name = trim((string) ($body['name'] ?? ''));
            $slug = trim((string) ($body['slug'] ?? ''));
            $desc = trim((string) ($body['description'] ?? ''));
            $permIds = isset($body['permission_ids']) && is_array($body['permission_ids']) ? $body['permission_ids'] : [];
            $errors = [];
            if ($name === '') {
                $errors['name'] = 'Name is required.';
            }
            if ($slug === '' || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
                $errors['slug'] = 'Use a kebab-case slug.';
            }
            if ($roles->findBySlug($slug) !== null) {
                $errors['slug'] = 'Slug already in use.';
            }
            if ($errors !== []) {
                return $twig->render($response, 'admin/roles/form.twig', $withCmsUser($request, array_merge($adminContext(), [
                    'admin_nav' => 'roles',
                    'form_mode' => 'create',
                    'role' => null,
                    'all_permissions' => $roles->allPermissions(),
                    'selected_perm_ids' => array_map('intval', $permIds),
                    'errors' => $errors,
                    'old' => ['name' => $name, 'slug' => $slug, 'description' => $desc],
                ])));
            }
            $rid = $roles->insert($name, $slug, $desc === '' ? null : $desc);
            $pids = [];
            foreach ($permIds as $p) {
                if (is_numeric($p)) {
                    $pids[] = (int) $p;
                }
            }
            $roles->syncPermissions($rid, $pids);
            $activity->log($cmsUid($request), 'role.created', 'role', $rid, ['slug' => $slug]);
            Flash::set('success', 'Role created.');

            return $response
                ->withHeader('Location', RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.roles.index'))
                ->withStatus(302);
        })->setName('admin.roles.store');

        $group->get('/roles/{id:[0-9]+}/edit', function (Request $request, Response $response, array $args) use ($twig, $adminContext, $withCmsUser, $roles): Response {
            $id = (int) $args['id'];
            $row = $roles->findById($id);
            if ($row === null) {
                throw new HttpNotFoundException($request);
            }

            return $twig->render($response, 'admin/roles/form.twig', $withCmsUser($request, array_merge($adminContext(), [
                'admin_nav' => 'roles',
                'form_mode' => 'edit',
                'role' => $row,
                'all_permissions' => $roles->allPermissions(),
                'selected_perm_ids' => $roles->permissionIdsForRole($id),
                'errors' => [],
                'old' => null,
            ])));
        })->setName('admin.roles.edit');

        $group->post('/roles/{id:[0-9]+}/edit', function (Request $request, Response $response, array $args) use ($twig, $adminContext, $withCmsUser, $roles, $activity, $cmsUid): Response {
            $id = (int) $args['id'];
            $row = $roles->findById($id);
            if ($row === null) {
                throw new HttpNotFoundException($request);
            }
            $body = $request->getParsedBody();
            $body = is_array($body) ? $body : [];
            $name = trim((string) ($body['name'] ?? ''));
            $slug = trim((string) ($body['slug'] ?? ''));
            $desc = trim((string) ($body['description'] ?? ''));
            $permIds = isset($body['permission_ids']) && is_array($body['permission_ids']) ? $body['permission_ids'] : [];
            $errors = [];
            if ($name === '') {
                $errors['name'] = 'Name is required.';
            }
            $isSystem = (int) ($row['is_system'] ?? 0) === 1;
            if (!$isSystem) {
                if ($slug === '' || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
                    $errors['slug'] = 'Use a kebab-case slug.';
                } else {
                    $other = $roles->findBySlug($slug);
                    if ($other !== null && (int) $other['id'] !== $id) {
                        $errors['slug'] = 'Slug already in use.';
                    }
                }
            }
            if ($errors !== []) {
                return $twig->render($response, 'admin/roles/form.twig', $withCmsUser($request, array_merge($adminContext(), [
                    'admin_nav' => 'roles',
                    'form_mode' => 'edit',
                    'role' => $row,
                    'all_permissions' => $roles->allPermissions(),
                    'selected_perm_ids' => array_map('intval', $permIds),
                    'errors' => $errors,
                    'old' => ['name' => $name, 'slug' => $slug, 'description' => $desc],
                ])));
            }
            if ($isSystem) {
                $roles->syncPermissions($id, array_map('intval', array_filter($permIds, 'is_numeric')));
            } else {
                $roles->update($id, $name, $slug, $desc === '' ? null : $desc);
                $pids = [];
                foreach ($permIds as $p) {
                    if (is_numeric($p)) {
                        $pids[] = (int) $p;
                    }
                }
                $roles->syncPermissions($id, $pids);
            }
            $activity->log($cmsUid($request), 'role.updated', 'role', $id, ['slug' => $row['slug']]);
            Flash::set('success', 'Role saved.');

            return $response
                ->withHeader('Location', RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.roles.index'))
                ->withStatus(302);
        })->setName('admin.roles.update');

        $group->post('/roles/{id:[0-9]+}/delete', function (Request $request, Response $response, array $args) use ($roles, $activity, $cmsUid): Response {
            $id = (int) $args['id'];
            if (!$roles->deleteIfCustom($id)) {
                Flash::set('error', 'System roles cannot be deleted.');
            } else {
                $activity->log($cmsUid($request), 'role.deleted', 'role', $id, []);
                Flash::set('success', 'Role deleted.');
            }

            return $response
                ->withHeader('Location', RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.roles.index'))
                ->withStatus(302);
        })->setName('admin.roles.delete');
    })->add($perm)->add($middleware);
};
