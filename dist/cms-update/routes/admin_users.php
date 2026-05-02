<?php

declare(strict_types=1);

use App\Access\ActivityLogger;
use App\Access\PermissionSlug;
use App\Access\RoleRepository;
use App\Access\RoleUserRepository;
use App\Auth\PhpAuthUsernameRepository;
use App\Auth\UsernameValidation;
use App\CmsUserRepository;
use App\Flash;
use App\Http\Middleware\RequireCmsStaff;
use App\Http\Middleware\RequirePermission;
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
    $perm = new RequirePermission($pdo, [PermissionSlug::MANAGE_USERS]);
    $roles = new RoleRepository($pdo);
    $roleUsers = new RoleUserRepository($pdo);
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

    $app->group('/admin', function (\Slim\Routing\RouteCollectorProxy $group) use ($twig, $adminContext, $withCmsUser, $auth, $pdo, $roles, $roleUsers, $activity, $cmsUid): void {
        $group->get('/users', function (Request $request, Response $response) use ($twig, $adminContext, $withCmsUser, $pdo): Response {
            $perPage = 25;
            $q = $request->getQueryParams();
            $page = isset($q['page']) ? (int) $q['page'] : 1;
            $page = max(1, $page);
            $total = CmsUserRepository::countAll($pdo);
            $totalPages = $total > 0 ? (int) ceil($total / $perPage) : 1;
            if ($page > $totalPages) {
                $page = $totalPages;
            }
            $offset = ($page - 1) * $perPage;
            $users = $total > 0 ? CmsUserRepository::listOrderedPage($pdo, $perPage, $offset) : [];

            return $twig->render($response, 'admin/users/index.twig', $withCmsUser($request, array_merge($adminContext(), [
                'admin_nav' => 'users',
                'users' => $users,
                'users_page' => $page,
                'users_total' => $total,
                'users_total_pages' => $totalPages,
                'users_per_page' => $perPage,
            ])));
        })->setName('admin.users.index');

        $group->get('/users/new', function (Request $request, Response $response) use ($twig, $adminContext, $withCmsUser, $roles): Response {
            return $twig->render($response, 'admin/users/form.twig', $withCmsUser($request, array_merge($adminContext(), [
                'admin_nav' => 'users',
                'form_mode' => 'create',
                'edit_user' => null,
                'all_roles' => $roles->allOrdered(),
                'selected_role_ids' => [],
                'errors' => [],
                'old' => ['email' => '', 'username' => '', 'display_name' => '', 'password' => '', 'password_confirm' => ''],
            ])));
        })->setName('admin.users.new');

        $group->post('/users/new', function (Request $request, Response $response) use ($twig, $adminContext, $withCmsUser, $auth, $pdo, $roles, $roleUsers, $activity, $cmsUid): Response {
            $body = $request->getParsedBody();
            $body = is_array($body) ? $body : [];
            $email = trim((string) ($body['email'] ?? ''));
            $usernameRaw = trim((string) ($body['username'] ?? ''));
            $display = trim((string) ($body['display_name'] ?? ''));
            $pass = (string) ($body['password'] ?? '');
            $pass2 = (string) ($body['password_confirm'] ?? '');
            $roleIds = isset($body['role_ids']) && is_array($body['role_ids']) ? $body['role_ids'] : [];
            $errors = [];
            $unameVal = UsernameValidation::validate($usernameRaw, false);
            if (!$unameVal['ok']) {
                $errors['username'] = $unameVal['message'];
            }
            if ($unameVal['ok'] && $unameVal['value'] !== '' && PhpAuthUsernameRepository::isTaken($pdo, $unameVal['value'])) {
                $errors['username'] = 'That username is already taken.';
            }
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors['email'] = 'Valid email required.';
            }
            if ($display === '') {
                $errors['display_name'] = 'Display name required.';
            }
            if (strlen($pass) < 8) {
                $errors['password'] = 'Password must be at least 8 characters.';
            }
            if ($pass !== $pass2) {
                $errors['password_confirm'] = 'Passwords do not match.';
            }
            if ($errors !== []) {
                return $twig->render($response, 'admin/users/form.twig', $withCmsUser($request, array_merge($adminContext(), [
                    'admin_nav' => 'users',
                    'form_mode' => 'create',
                    'edit_user' => null,
                    'all_roles' => $roles->allOrdered(),
                    'selected_role_ids' => array_map('intval', $roleIds),
                    'errors' => $errors,
                    'old' => ['email' => $email, 'username' => $usernameRaw, 'display_name' => $display, 'password' => '', 'password_confirm' => ''],
                ])));
            }
            $reg = $auth->register($email, $pass, $pass2, [], '', false);
            if (($reg['error'] ?? true) === true) {
                $errors['email'] = (string) ($reg['message'] ?? 'Registration failed.');

                return $twig->render($response, 'admin/users/form.twig', $withCmsUser($request, array_merge($adminContext(), [
                    'admin_nav' => 'users',
                    'form_mode' => 'create',
                    'edit_user' => null,
                    'all_roles' => $roles->allOrdered(),
                    'selected_role_ids' => array_map('intval', $roleIds),
                    'errors' => $errors,
                    'old' => ['email' => $email, 'username' => $usernameRaw, 'display_name' => $display, 'password' => '', 'password_confirm' => ''],
                ])));
            }
            $uid = (int) ($reg['uid'] ?? 0);
            if ($unameVal['value'] !== '' && $uid > 0) {
                PhpAuthUsernameRepository::setForUserId($pdo, $uid, $unameVal['value']);
            }
            $cmsId = CmsUserRepository::insert($pdo, $uid, $email, $display);
            $rids = [];
            foreach ($roleIds as $r) {
                if (is_numeric($r)) {
                    $rids[] = (int) $r;
                }
            }
            $roleUsers->replaceForUser($cmsId, $rids);
            $activity->log($cmsUid($request), 'user.created', 'cms_user', $cmsId, ['email' => $email]);
            Flash::set('success', 'User created.');

            return $response
                ->withHeader('Location', RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.users.index'))
                ->withStatus(302);
        })->setName('admin.users.store');

        $group->get('/users/{id:[0-9]+}/edit', function (Request $request, Response $response, array $args) use ($twig, $adminContext, $withCmsUser, $pdo, $roles, $roleUsers): Response {
            $id = (int) $args['id'];
            $u = CmsUserRepository::findById($pdo, $id);
            if ($u === null) {
                throw new HttpNotFoundException($request);
            }
            $totp = CmsUserRepository::findTotpStateByPhpAuthId($pdo, (int) $u['phpauth_user_id']);

            return $twig->render($response, 'admin/users/form.twig', $withCmsUser($request, array_merge($adminContext(), [
                'admin_nav' => 'users',
                'form_mode' => 'edit',
                'edit_user' => $u,
                'edit_user_totp' => $totp,
                'all_roles' => $roles->allOrdered(),
                'selected_role_ids' => $roleUsers->roleIdsForUser($id),
                'errors' => [],
                'old' => null,
            ])));
        })->setName('admin.users.edit');

        $group->post('/users/{id:[0-9]+}/edit', function (Request $request, Response $response, array $args) use ($twig, $adminContext, $withCmsUser, $auth, $pdo, $roles, $roleUsers, $activity, $cmsUid): Response {
            $id = (int) $args['id'];
            $u = CmsUserRepository::findById($pdo, $id);
            if ($u === null) {
                throw new HttpNotFoundException($request);
            }
            $body = $request->getParsedBody();
            $body = is_array($body) ? $body : [];
            $email = trim((string) ($body['email'] ?? ''));
            $usernameRaw = trim((string) ($body['username'] ?? ''));
            $display = trim((string) ($body['display_name'] ?? ''));
            $active = !empty($body['is_active']);
            $newPass = (string) ($body['new_password'] ?? '');
            $newPass2 = (string) ($body['new_password_confirm'] ?? '');
            $roleIds = isset($body['role_ids']) && is_array($body['role_ids']) ? $body['role_ids'] : [];
            $errors = [];
            $phpauthId = (int) $u['phpauth_user_id'];

            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors['email'] = 'Valid email required.';
            } elseif (CmsUserRepository::phpauthEmailTakenByOther($pdo, $email, $phpauthId)) {
                $errors['email'] = 'That email is already in use by another account.';
            }

            if ($newPass !== '' || $newPass2 !== '') {
                if (strlen($newPass) < 8) {
                    $errors['new_password'] = 'New password must be at least 8 characters.';
                }
                if ($newPass !== $newPass2) {
                    $errors['new_password_confirm'] = 'Passwords do not match.';
                }
            }

            $unameVal = UsernameValidation::validate($usernameRaw, false);
            if (!$unameVal['ok']) {
                $errors['username'] = $unameVal['message'];
            }
            if ($unameVal['ok'] && $unameVal['value'] !== '' && PhpAuthUsernameRepository::isTaken($pdo, $unameVal['value'], $phpauthId)) {
                $errors['username'] = 'That username is already taken.';
            }
            if ($display === '') {
                $errors['display_name'] = 'Display name required.';
            }
            if ($errors !== []) {
                $totp = CmsUserRepository::findTotpStateByPhpAuthId($pdo, $phpauthId);

                return $twig->render($response, 'admin/users/form.twig', $withCmsUser($request, array_merge($adminContext(), [
                    'admin_nav' => 'users',
                    'form_mode' => 'edit',
                    'edit_user' => $u,
                    'edit_user_totp' => $totp,
                    'all_roles' => $roles->allOrdered(),
                    'selected_role_ids' => array_map('intval', $roleIds),
                    'errors' => $errors,
                    'old' => [
                        'email' => $email,
                        'username' => $usernameRaw,
                        'display_name' => $display,
                        'is_active' => $active,
                        'new_password' => '',
                        'new_password_confirm' => '',
                    ],
                ])));
            }
            if (strcasecmp($email, (string) $u['email']) !== 0) {
                CmsUserRepository::updateEmail($pdo, $id, $phpauthId, $email);
            }
            if ($newPass !== '') {
                $cost = (int) ($auth->config->bcrypt_cost ?? 10);
                $hash = Auth::getHash($newPass, $cost);
                CmsUserRepository::updatePhpAuthPasswordHash($pdo, $phpauthId, $hash);
            }
            CmsUserRepository::updateProfile($pdo, $id, $display);
            CmsUserRepository::setCmsActive($pdo, $id, $active);
            CmsUserRepository::setPhpAuthActive($pdo, $phpauthId, $active);
            PhpAuthUsernameRepository::setForUserId(
                $pdo,
                $phpauthId,
                $unameVal['value'] === '' ? null : $unameVal['value']
            );
            $rids = [];
            foreach ($roleIds as $r) {
                if (is_numeric($r)) {
                    $rids[] = (int) $r;
                }
            }
            $roleUsers->replaceForUser($id, $rids);
            $activity->log($cmsUid($request), 'user.updated', 'cms_user', $id, ['email' => $email]);
            Flash::set('success', 'User saved.');

            return $response
                ->withHeader('Location', RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.users.edit', ['id' => (string) $id]))
                ->withStatus(302);
        })->setName('admin.users.update');
    })->add($perm)->add($middleware);
};
