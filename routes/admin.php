<?php

declare(strict_types=1);

use App\Admin\DashboardStatsCollector;
use App\Filter\FilterHook;
use App\Filter\Filters;
use App\Http\Middleware\RequireCmsStaff;
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

    $adminContext = static function () use ($viewData): array {
        return array_merge($viewData(), []);
    };

    $app->group('/admin', function (\Slim\Routing\RouteCollectorProxy $group) use ($twig, $adminContext, $pdo): void {
        $handler = static function (Request $request, Response $response) use ($twig, $adminContext, $pdo): Response {
            /** @var array<string, mixed> $cmsUser */
            $cmsUser = $request->getAttribute('cms_user') ?? [];
            $stats = (new DashboardStatsCollector($pdo))->collect();
            $stats = Filters::apply(FilterHook::ADMIN_DASHBOARD, $stats, []);
            if (!is_array($stats)) {
                $stats = (new DashboardStatsCollector($pdo))->collect();
            }

            return $twig->render($response, 'admin/dashboard.twig', array_merge($adminContext(), [
                'cms_user' => $cmsUser,
                'admin_nav' => 'dashboard',
                'dashboard_stats' => $stats,
            ]));
        };

        $group->get('', $handler)->setName('admin.dashboard');
        $group->get('/', $handler);
    })->add($middleware);
};
