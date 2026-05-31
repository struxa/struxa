<?php

declare(strict_types=1);

use App\Access\ActivityLogger;
use App\Access\PermissionSlug;
use App\Content\ContentEntryRepository;
use App\Event\Events;
use App\Event\StorefrontCachesInvalidateEvent;
use App\Flash;
use App\Http\Middleware\RequireCmsStaff;
use App\Http\Middleware\RequirePermission;
use App\Media\MediaDeletionService;
use App\Media\MediaRepository;
use App\Page\PageRepository;
use App\Trash\TrashAdminService;
use App\Trash\TrashItemKind;
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
    $permView = new RequirePermission($pdo, [
        PermissionSlug::MANAGE_PAGES,
        PermissionSlug::EDIT_CONTENT,
        PermissionSlug::REVIEW_CONTENT,
        PermissionSlug::MANAGE_MEDIA,
    ]);
    $permPurge = new RequirePermission($pdo, [PermissionSlug::DELETE_CONTENT]);

    $pages = new PageRepository($pdo);
    $entries = new ContentEntryRepository($pdo);
    $mediaRepo = new MediaRepository($pdo);
    $root = dirname(__DIR__);
    $mediaDeletion = new MediaDeletionService($mediaRepo, $root);
    $trash = new TrashAdminService($pages, $entries, $mediaRepo, $mediaDeletion);
    $activity = new ActivityLogger($pdo);

    $adminContext = static function () use ($viewData): array {
        return array_merge($viewData(), []);
    };

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

    $app->group('/admin/trash', function (\Slim\Routing\RouteCollectorProxy $group) use (
        $twig,
        $adminContext,
        $withCmsUser,
        $trash,
        $activity,
        $cmsUid,
        $permPurge,
    ): void {
        $group->get('', function (Request $request, Response $response) use ($twig, $adminContext, $withCmsUser, $trash): Response {
            return $twig->render($response, 'admin/trash/index.twig', $withCmsUser($request, array_merge($adminContext(), [
                'admin_nav' => 'trash',
                'trash_items' => $trash->listItems(),
                'trash_count' => $trash->countItems(),
            ])));
        })->setName('admin.trash.index');

        $group->post('/restore', function (Request $request, Response $response) use ($trash, $activity, $cmsUid): Response {
            $body = $request->getParsedBody();
            $body = is_array($body) ? $body : [];
            $kind = trim((string) ($body['kind'] ?? ''));
            $id = (int) ($body['id'] ?? 0);

            if (!TrashItemKind::isValid($kind) || $id < 1) {
                Flash::set('error', 'Could not restore that item.');
            } elseif ($trash->restore($kind, $id)) {
                $activity->log($cmsUid($request), 'trash.restored', $kind, $id, []);
                Flash::set('success', 'Item restored from trash.');
                Events::dispatch(new StorefrontCachesInvalidateEvent('trash_restored'));
            } else {
                Flash::set('error', 'That item is no longer in trash.');
            }

            return $response
                ->withHeader('Location', RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.trash.index'))
                ->withStatus(302);
        })->setName('admin.trash.restore');

        $group->post('/purge', function (Request $request, Response $response) use ($trash, $activity, $cmsUid): Response {
            $body = $request->getParsedBody();
            $body = is_array($body) ? $body : [];
            $kind = trim((string) ($body['kind'] ?? ''));
            $id = (int) ($body['id'] ?? 0);

            if (!TrashItemKind::isValid($kind) || $id < 1) {
                Flash::set('error', 'Could not delete that item.');
            } elseif ($trash->purge($kind, $id)) {
                $activity->log($cmsUid($request), 'trash.purged', $kind, $id, []);
                Flash::set('success', 'Item permanently deleted.');
                Events::dispatch(new StorefrontCachesInvalidateEvent('trash_purged'));
            } else {
                Flash::set('error', 'That item is no longer in trash.');
            }

            return $response
                ->withHeader('Location', RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.trash.index'))
                ->withStatus(302);
        })->setName('admin.trash.purge')->add($permPurge);
    })->add($permView)->add($middleware);
};
