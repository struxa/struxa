<?php

declare(strict_types=1);

use App\Access\PermissionSlug;
use App\Content\ContentEntryRepository;
use App\Editing\ContentAutosaveRepository;
use App\Editing\EditLockService;
use App\Editing\EditSessionContext;
use App\Editing\EditSubjectType;
use App\Editing\EditLockRepository;
use App\Http\Middleware\RequireCmsStaff;
use App\Page\PageRepository;
use PHPAuth\Auth;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Slim\Exception\HttpNotFoundException;

return static function (App $app, Auth $auth, \PDO $pdo): void {
    $middleware = new RequireCmsStaff($auth, $pdo);

    $locks = new EditLockService(new EditLockRepository($pdo));
    $autosaves = new ContentAutosaveRepository($pdo);
    $sessions = new EditSessionContext($locks, $autosaves);
    $pages = new PageRepository($pdo);
    $entries = new ContentEntryRepository($pdo);

    $json = static function (Response $response, array $payload, int $status = 200): Response {
        $response->getBody()->write(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $response->withStatus($status)->withHeader('Content-Type', 'application/json; charset=utf-8');
    };

    $cmsUid = static function (Request $request): int {
        /** @var array<string, mixed> $u */
        $u = $request->getAttribute('cms_user') ?? [];
        $id = isset($u['id']) ? (int) $u['id'] : 0;
        if ($id <= 0) {
            throw new HttpNotFoundException($request);
        }

        return $id;
    };

    $parseBody = static function (Request $request): array {
        $body = $request->getParsedBody();
        if (!is_array($body)) {
            $raw = (string) $request->getBody();
            if ($raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }

            return [];
        }

        return $body;
    };

    $assertCanEdit = static function (Request $request, string $subjectType): void {
        /** @var array<string, mixed> $cmsUser */
        $cmsUser = $request->getAttribute('cms_user') ?? [];
        $perms = is_array($cmsUser['permission_slugs'] ?? null) ? $cmsUser['permission_slugs'] : [];
        if ($subjectType === EditSubjectType::PAGE) {
            if (!in_array(PermissionSlug::MANAGE_PAGES, $perms, true)) {
                throw new HttpNotFoundException($request);
            }

            return;
        }
        if (
            !in_array(PermissionSlug::EDIT_CONTENT, $perms, true)
            && !in_array(PermissionSlug::REVIEW_CONTENT, $perms, true)
        ) {
            throw new HttpNotFoundException($request);
        }
    };

    $assertSubjectAccess = static function (
        Request $request,
        string $subjectType,
        int $subjectId,
    ) use ($pages, $entries, $assertCanEdit): void {
        if ($subjectId <= 0 || !EditSubjectType::isValid($subjectType)) {
            throw new HttpNotFoundException($request);
        }
        $assertCanEdit($request, $subjectType);
        if ($subjectType === EditSubjectType::PAGE) {
            if ($pages->findById($subjectId) === null) {
                throw new HttpNotFoundException($request);
            }

            return;
        }
        if ($entries->findById($subjectId) === null) {
            throw new HttpNotFoundException($request);
        }
    };

    $app->group('/admin/editing', function (\Slim\Routing\RouteCollectorProxy $group) use (
        $json,
        $cmsUid,
        $parseBody,
        $assertSubjectAccess,
        $locks,
        $sessions,
    ): void {
        $group->post('/session', function (Request $request, Response $response) use (
            $json,
            $cmsUid,
            $parseBody,
            $assertSubjectAccess,
            $locks,
            $sessions,
        ): Response {
            $body = $parseBody($request);
            $subjectType = trim((string) ($body['subject_type'] ?? ''));
            $subjectId = (int) ($body['subject_id'] ?? 0);
            $lockToken = trim((string) ($body['lock_token'] ?? ''));
            $action = trim((string) ($body['action'] ?? 'heartbeat'));
            $userId = $cmsUid($request);

            if (!EditSubjectType::isValid($subjectType) || $subjectId <= 0 || $lockToken === '') {
                return $json($response, ['ok' => false, 'error' => 'invalid_request'], 422);
            }

            $assertSubjectAccess($request, $subjectType, $subjectId);

            $autosaveSaved = false;
            $payload = $body['payload'] ?? null;
            if (is_array($payload) && $payload !== [] && in_array($action, ['heartbeat', 'acquire'], true)) {
                $autosaveSaved = $sessions->saveAutosave($subjectType, $subjectId, $userId, $payload);
            }

            $result = match ($action) {
                'acquire' => $locks->acquireOrRenew($subjectType, $subjectId, $userId, $lockToken),
                'takeover' => $locks->takeover($subjectType, $subjectId, $userId, $lockToken),
                'release' => (function () use ($locks, $subjectType, $subjectId, $userId, $lockToken): array {
                    $locks->release($subjectType, $subjectId, $userId, $lockToken);

                    return ['status' => 'released', 'lock' => null, 'holder' => null];
                })(),
                default => $locks->heartbeat($subjectType, $subjectId, $userId, $lockToken),
            };

            $blocked = $result['status'] === 'blocked';
            $holder = $result['holder'];

            return $json($response, [
                'ok' => !$blocked,
                'action' => $action,
                'lock_status' => $result['status'],
                'blocked' => $blocked,
                'holder' => $holder,
                'expires_in_seconds' => EditLockService::TTL_SECONDS,
                'autosave_saved' => $autosaveSaved,
                'server_time' => gmdate('c'),
            ], $blocked ? 409 : 200);
        })->setName('admin.editing.session');
    })->add($middleware);
};
