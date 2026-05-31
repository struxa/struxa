<?php

declare(strict_types=1);

use App\Access\PermissionSlug;
use App\Health\SiteHealthService;
use App\Health\SiteHealthStatus;
use App\Http\Middleware\RequireCmsStaff;
use App\Http\Middleware\RequirePermission;
use App\Settings\SiteUrlResolver;
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
    $perm = new RequirePermission($pdo, [PermissionSlug::MANAGE_SETTINGS]);
    $root = dirname(__DIR__);
    $health = new SiteHealthService($pdo, $root);

    $adminContext = static fn (): array => array_merge($viewData(), []);
    $withCmsUser = static function (Request $request, array $data): array {
        /** @var array<string, mixed> $cmsUser */
        $cmsUser = $request->getAttribute('cms_user') ?? [];

        return array_merge($data, ['cms_user' => $cmsUser]);
    };

    $app->group('/admin', function (\Slim\Routing\RouteCollectorProxy $group) use (
        $twig,
        $adminContext,
        $withCmsUser,
        $health,
    ): void {
        $group->get('/tools/site-health', function (Request $request, Response $response) use (
            $twig,
            $adminContext,
            $withCmsUser,
            $health,
        ): Response {
            $uri = $request->getUri();
            $report = $health->report([
                'request_is_https' => strtolower($uri->getScheme()) === 'https',
                'site_url' => SiteUrlResolver::resolve(),
                'server_software' => trim((string) ($request->getServerParams()['SERVER_SOFTWARE'] ?? '')),
            ]);

            return $twig->render($response, 'admin/tools/site_health.twig', $withCmsUser($request, array_merge($adminContext(), [
                'admin_nav' => 'site_health',
                'health_report' => $report,
                'health_overall' => $report->overallStatus(),
                'health_counts' => $report->counts(),
                'health_groups' => $report->byGroup(),
                'health_group_labels' => [
                    'environment' => 'Environment',
                    'storage' => 'Storage & permissions',
                    'database' => 'Database',
                    'operations' => 'Operations',
                    'security' => 'Security',
                    'plugins' => 'Plugins',
                ],
                'health_status_labels' => [
                    SiteHealthStatus::GOOD => 'Good',
                    SiteHealthStatus::RECOMMENDED => 'Recommended',
                    SiteHealthStatus::CRITICAL => 'Critical',
                ],
            ])));
        })->setName('admin.tools.site_health');
    })->add($perm)->add($middleware);
};
