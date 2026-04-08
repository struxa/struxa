<?php

declare(strict_types=1);

use App\Http\Middleware\RequireCmsStaff;
use App\SiteProfile\SiteProfileRepository;
use App\Update\CmsSelfUpdater;
use App\Update\CmsUpdateChecker;
use App\Cache\CacheManager;
use PHPAuth\Auth;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Slim\Routing\RouteContext;
use Slim\Views\Twig;

/**
 * @param callable(): array<string, mixed> $viewData
 */
return static function (App $app, Twig $twig, Auth $auth, \PDO $pdo, callable $viewData): void {
    $middleware = new RequireCmsStaff($auth, $pdo);
    $root = dirname(__DIR__);
    $cacheInternal = (new CacheManager($root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'cache'))->internal();
    $checker = new CmsUpdateChecker($cacheInternal, $pdo);

    $adminContext = static function () use ($viewData): array {
        return array_merge($viewData(), []);
    };

    $withCmsUser = static function (Request $request, array $data): array {
        /** @var array<string, mixed> $cmsUser */
        $cmsUser = $request->getAttribute('cms_user') ?? [];

        return array_merge($data, ['cms_user' => $cmsUser]);
    };

    $jsonResponse = static function (Response $response, array $payload, int $status = 200): Response {
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;
        $response->getBody()->write(json_encode($payload, $flags));

        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store');
    };

    $app->group('/admin', function (\Slim\Routing\RouteCollectorProxy $group) use (
        $twig,
        $adminContext,
        $withCmsUser,
        $checker,
        $jsonResponse,
        $root,
        $pdo,
        $cacheInternal
    ): void {
        $group->get('/updates', function (Request $request, Response $response) use ($twig, $adminContext, $withCmsUser, $checker, $root): Response {
            $qp = $request->getQueryParams();
            $fresh = isset($qp['fresh']) && ($qp['fresh'] === '1' || $qp['fresh'] === 'true');
            $status = $checker->check($fresh);
            $parser = RouteContext::fromRequest($request)->getRouteParser();

            return $twig->render($response, 'admin/updates/index.twig', $withCmsUser($request, array_merge($adminContext(), [
                'admin_nav' => 'updates',
                'cms_update_page_status' => $status,
                'cms_update_poll_url' => $parser->urlFor('admin.updates.status'),
                'cms_update_apply_url' => $parser->urlFor('admin.updates.apply'),
                'cms_auto_update_allowed' => CmsSelfUpdater::autoUpdateAllowedByEnv($root),
            ])));
        })->setName('admin.updates.index');

        $group->get('/updates/status', function (Request $request, Response $response) use ($checker, $jsonResponse): Response {
            $qp = $request->getQueryParams();
            $fresh = isset($qp['fresh']) && ($qp['fresh'] === '1' || $qp['fresh'] === 'true');
            $status = $checker->check($fresh);

            return $jsonResponse($response, $status);
        })->setName('admin.updates.status');

        $group->post('/updates/apply', function (Request $request, Response $response) use (
            $checker,
            $jsonResponse,
            $root,
            $pdo,
            $cacheInternal
        ): Response {
            if (!CmsSelfUpdater::autoUpdateAllowedByEnv($root)) {
                return $jsonResponse($response, [
                    'ok' => false,
                    'message' => 'Automatic updates are disabled. Add STRUXA_ALLOW_AUTO_UPDATE=1 to .env to enable.',
                    'warnings' => [],
                ], 403);
            }
            $st = $checker->check(true);
            $result = (new CmsSelfUpdater())->apply($root, $st);
            if ($result['ok']) {
                $profile = new SiteProfileRepository($pdo);
                $latest = trim((string) ($st['latest_version'] ?? ''));
                if ($latest !== '') {
                    $profile->setInstalledVersionString($latest);
                } else {
                    $profile->syncInstalledVersion();
                }
                $cacheInternal->clear();
                $fresh = $checker->check(true);
                $applied = trim((string) ($result['applied_version'] ?? ''));
                if ($applied !== '') {
                    $fresh['current_version'] = $applied;
                    $lv = trim((string) ($fresh['latest_version'] ?? ''));
                    if ($lv !== '') {
                        $fresh['update_available'] = version_compare($lv, $applied, '>');
                    } else {
                        $fresh['update_available'] = false;
                    }
                }
                $result['status'] = $fresh;
            }

            return $jsonResponse($response, $result, $result['ok'] ? 200 : 422);
        })->setName('admin.updates.apply');
    })->add($middleware);
};
