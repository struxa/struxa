<?php

declare(strict_types=1);

use App\Access\PermissionSlug;
use App\Comment\CommentRepository;
use App\Flash;
use App\Http\Middleware\RequireCmsStaff;
use App\Http\Middleware\RequirePermission;
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
    $perm = new RequirePermission($pdo, [PermissionSlug::MANAGE_COMMENTS]);
    $repo = new CommentRepository($pdo);

    $adminContext = static fn (): array => array_merge($viewData(), []);
    $withCmsUser = static function (Request $request, array $data): array {
        /** @var array<string, mixed> $cmsUser */
        $cmsUser = $request->getAttribute('cms_user') ?? [];

        return array_merge($data, ['cms_user' => $cmsUser]);
    };

    $app->group('/admin', function (\Slim\Routing\RouteCollectorProxy $group) use ($twig, $repo, $adminContext, $withCmsUser): void {
        $group->get('/comments', function (Request $request, Response $response) use ($twig, $repo, $adminContext, $withCmsUser): Response {
            $q = $request->getQueryParams();
            $status = isset($q['status']) && is_string($q['status']) ? trim($q['status']) : 'pending';
            $rows = $repo->listForAdmin($status, 400);

            return $twig->render($response, 'admin/comments/index.twig', $withCmsUser($request, array_merge($adminContext(), [
                'admin_nav' => 'comments',
                'comment_status' => $status,
                'comment_rows' => $rows,
                'comment_pending_count' => $repo->countByStatus('pending'),
                'comment_approved_count' => $repo->countByStatus('approved'),
                'comment_rejected_count' => $repo->countByStatus('rejected'),
                'comment_spam_count' => $repo->countByStatus('spam'),
            ])));
        })->setName('admin.comments.index');

        $group->post('/comments/{id:[0-9]+}/status', function (Request $request, Response $response, array $args) use ($repo): Response {
            $id = (int) ($args['id'] ?? 0);
            $body = $request->getParsedBody();
            $body = is_array($body) ? $body : [];
            $status = isset($body['status']) && is_string($body['status']) ? trim($body['status']) : 'pending';
            $fromStatus = isset($body['from_status']) && is_string($body['from_status']) ? trim($body['from_status']) : 'pending';
            if ($repo->setStatus($id, $status)) {
                Flash::set('success', 'Comment updated.');
            } else {
                Flash::set('error', 'Could not update comment.');
            }
            $url = RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.comments.index');
            $url .= '?' . http_build_query(['status' => $fromStatus]);

            return $response->withHeader('Location', $url)->withStatus(302);
        })->setName('admin.comments.status');

        $group->post('/comments/{id:[0-9]+}/delete', function (Request $request, Response $response, array $args) use ($repo): Response {
            $id = (int) ($args['id'] ?? 0);
            $body = $request->getParsedBody();
            $body = is_array($body) ? $body : [];
            $fromStatus = isset($body['from_status']) && is_string($body['from_status']) ? trim($body['from_status']) : 'pending';
            if ($repo->delete($id)) {
                Flash::set('success', 'Comment deleted.');
            } else {
                Flash::set('error', 'Could not delete comment.');
            }
            $url = RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.comments.index');
            $url .= '?' . http_build_query(['status' => $fromStatus]);

            return $response->withHeader('Location', $url)->withStatus(302);
        })->setName('admin.comments.delete');
    })->add($perm)->add($middleware);
};
