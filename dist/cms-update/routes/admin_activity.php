<?php

declare(strict_types=1);

use App\Access\ActivityLogRepository;
use App\Access\PermissionSlug;
use App\Http\Middleware\RequireCmsStaff;
use App\Http\Middleware\RequirePermission;
use PHPAuth\Auth;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Slim\Views\Twig;

/**
 * @param callable(): array<string, mixed> $viewData
 */
return static function (App $app, Twig $twig, Auth $auth, \PDO $pdo, callable $viewData): void {
    $middleware = new RequireCmsStaff($auth, $pdo);
    $perm = new RequirePermission($pdo, [PermissionSlug::VIEW_ACTIVITY]);
    $repo = new ActivityLogRepository($pdo);

    $adminContext = static fn (): array => array_merge($viewData(), []);
    $withCmsUser = static function (Request $request, array $data): array {
        /** @var array<string, mixed> $cmsUser */
        $cmsUser = $request->getAttribute('cms_user') ?? [];

        return array_merge($data, ['cms_user' => $cmsUser]);
    };

    $app->group('/admin', function (\Slim\Routing\RouteCollectorProxy $group) use ($twig, $adminContext, $withCmsUser, $repo): void {
        $group->get('/activity', function (Request $request, Response $response) use ($twig, $adminContext, $withCmsUser, $repo): Response {
            $perPage = 25;
            $q = $request->getQueryParams();
            $page = isset($q['page']) ? (int) $q['page'] : 1;
            $page = max(1, $page);
            $total = $repo->countAll();
            $totalPages = $total > 0 ? (int) ceil($total / $perPage) : 1;
            if ($page > $totalPages) {
                $page = $totalPages;
            }
            $offset = ($page - 1) * $perPage;
            $logRows = $total > 0 ? $repo->listOrderedPage($perPage, $offset) : [];

            return $twig->render($response, 'admin/activity/index.twig', $withCmsUser($request, array_merge($adminContext(), [
                'admin_nav' => 'activity',
                'log_rows' => $logRows,
                'activity_page' => $page,
                'activity_total' => $total,
                'activity_total_pages' => $totalPages,
                'activity_per_page' => $perPage,
            ])));
        })->setName('admin.activity.index');
    })->add($perm)->add($middleware);
};
