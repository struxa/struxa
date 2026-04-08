<?php

declare(strict_types=1);

use App\Access\PermissionSlug;
use App\Cache\CacheManager;
use App\Flash;
use App\Http\Middleware\RequireCmsStaff;
use App\Http\Middleware\RequirePermission;
use App\Security\IpBlockPatternValidator;
use App\Security\IpBlockRepository;
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
    $perm = new RequirePermission($pdo, [PermissionSlug::MANAGE_SECURITY]);
    $root = dirname(__DIR__);
    $cacheManager = new CacheManager($root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'cache');
    $repo = new IpBlockRepository($pdo);
    $internal = $cacheManager->internal();

    $adminContext = static fn (): array => array_merge($viewData(), []);
    $withCmsUser = static function (Request $request, array $data): array {
        /** @var array<string, mixed> $cmsUser */
        $cmsUser = $request->getAttribute('cms_user') ?? [];

        return array_merge($data, ['cms_user' => $cmsUser]);
    };

    $invalidateIpBlockCache = static function () use ($internal): void {
        $internal->delete(IpBlockRepository::CACHE_KEY);
    };

    $app->group('/admin', function (\Slim\Routing\RouteCollectorProxy $group) use (
        $twig,
        $adminContext,
        $withCmsUser,
        $repo,
        $invalidateIpBlockCache
    ): void {
        $group->get('/security/ip-block', function (Request $request, Response $response) use (
            $twig,
            $adminContext,
            $withCmsUser,
            $repo
        ): Response {
            $rows = $repo->listRows();

            return $twig->render($response, 'admin/security/ip_block.twig', $withCmsUser($request, array_merge($adminContext(), [
                'admin_nav' => 'security_ip_block',
                'ip_block_rows' => $rows,
            ])));
        })->setName('admin.security.ip_block');

        $group->post('/security/ip-block/add', function (Request $request, Response $response) use (
            $repo,
            $invalidateIpBlockCache
        ): Response {
            $routeParser = RouteContext::fromRequest($request)->getRouteParser();
            $back = $routeParser->urlFor('admin.security.ip_block');
            $body = $request->getParsedBody();
            $raw = is_array($body) ? trim((string) ($body['pattern'] ?? '')) : '';
            $note = is_array($body) && isset($body['note']) && is_string($body['note']) ? $body['note'] : '';

            $norm = IpBlockPatternValidator::normalize($raw);
            if (!$norm['ok']) {
                Flash::set('error', $norm['error']);

                return $response->withHeader('Location', $back)->withStatus(302);
            }

            $ins = $repo->insert($norm['pattern'], $note);
            if (!$ins['ok']) {
                Flash::set('error', 'That pattern is already blocked.');

                return $response->withHeader('Location', $back)->withStatus(302);
            }

            $invalidateIpBlockCache();
            Flash::set('success', 'IP block added.');

            return $response->withHeader('Location', $back)->withStatus(302);
        })->setName('admin.security.ip_block_add');

        $group->post('/security/ip-block/delete', function (Request $request, Response $response) use (
            $repo,
            $invalidateIpBlockCache
        ): Response {
            $routeParser = RouteContext::fromRequest($request)->getRouteParser();
            $back = $routeParser->urlFor('admin.security.ip_block');
            $body = $request->getParsedBody();
            $id = is_array($body) ? (int) ($body['id'] ?? 0) : 0;
            if ($id < 1 || !$repo->deleteById($id)) {
                Flash::set('error', 'Could not remove that block.');

                return $response->withHeader('Location', $back)->withStatus(302);
            }
            $invalidateIpBlockCache();
            Flash::set('success', 'IP block removed.');

            return $response->withHeader('Location', $back)->withStatus(302);
        })->setName('admin.security.ip_block_delete');
    })->add($perm)->add($middleware);
};
