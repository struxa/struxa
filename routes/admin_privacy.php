<?php

declare(strict_types=1);

use App\Access\ActivityLogger;
use App\Access\PermissionSlug;
use App\Flash;
use App\Http\Middleware\RequireCmsStaff;
use App\Http\Middleware\RequirePermission;
use App\Privacy\PersonalDataErasureService;
use App\Privacy\PersonalDataExportService;
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
    $perm = new RequirePermission($pdo, [PermissionSlug::MANAGE_SETTINGS]);
    $exportService = new PersonalDataExportService($pdo);
    $eraseService = new PersonalDataErasureService($pdo);
    $activity = new ActivityLogger($pdo);

    $adminContext = static fn (): array => array_merge($viewData(), []);
    $withCmsUser = static function (Request $request, array $data): array {
        /** @var array<string, mixed> $cmsUser */
        $cmsUser = $request->getAttribute('cms_user') ?? [];

        return array_merge($data, ['cms_user' => $cmsUser]);
    };

    $cmsUserId = static function (Request $request): ?int {
        /** @var array<string, mixed> $u */
        $u = $request->getAttribute('cms_user') ?? [];
        $id = isset($u['id']) ? (int) $u['id'] : 0;

        return $id > 0 ? $id : null;
    };

    $app->group('/admin', function (\Slim\Routing\RouteCollectorProxy $group) use (
        $twig,
        $adminContext,
        $withCmsUser,
        $exportService,
        $eraseService,
        $activity,
        $cmsUserId
    ): void {
        $group->get('/tools/privacy', function (Request $request, Response $response) use (
            $twig,
            $adminContext,
            $withCmsUser
        ): Response {
            return $twig->render($response, 'admin/tools/privacy.twig', $withCmsUser($request, array_merge($adminContext(), [
                'admin_nav' => 'privacy_tools',
            ])));
        })->setName('admin.tools.privacy');

        $group->post('/tools/privacy/export', function (Request $request, Response $response) use (
            $exportService,
            $activity,
            $cmsUserId
        ): Response {
            $body = $request->getParsedBody();
            $body = is_array($body) ? $body : [];
            $email = trim((string) ($body['email'] ?? ''));
            $back = RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.tools.privacy');

            $result = $exportService->export($email);
            if (($result['ok'] ?? false) !== true) {
                Flash::set('error', (string) ($result['error'] ?? 'Export failed.'));

                return $response->withHeader('Location', $back)->withStatus(302);
            }

            /** @var array<string, mixed> $data */
            $data = $result['data'];
            $activity->log($cmsUserId($request), 'privacy.export', null, null, [
                'email' => $data['email'] ?? $email,
                'comments' => count($data['comments'] ?? []),
                'form_submissions' => count($data['form_submissions'] ?? []),
            ]);

            $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            $filename = 'personal-data-export-' . preg_replace('/[^a-z0-9@._-]+/i', '-', (string) ($data['email'] ?? 'user')) . '.json';

            $response->getBody()->write($json);

            return $response
                ->withHeader('Content-Type', 'application/json; charset=utf-8')
                ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"');
        })->setName('admin.tools.privacy.export');

        $group->post('/tools/privacy/erase', function (Request $request, Response $response) use (
            $eraseService,
            $activity,
            $cmsUserId
        ): Response {
            $body = $request->getParsedBody();
            $body = is_array($body) ? $body : [];
            $email = trim((string) ($body['email'] ?? ''));
            $confirm = trim((string) ($body['confirm_erase'] ?? ''));
            $back = RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.tools.privacy');

            if ($confirm !== 'ERASE') {
                Flash::set('error', 'Type ERASE in the confirmation box to permanently remove personal data.');

                return $response->withHeader('Location', $back)->withStatus(302);
            }

            $result = $eraseService->erase($email);
            if (($result['ok'] ?? false) !== true) {
                Flash::set('error', (string) ($result['error'] ?? 'Erasure failed.'));

                return $response->withHeader('Location', $back)->withStatus(302);
            }

            /** @var \App\Privacy\PersonalDataErasureResult $eraseResult */
            $eraseResult = $result['result'];
            $activity->log($cmsUserId($request), 'privacy.erase', null, null, [
                'email' => $email,
                'deleted' => $eraseResult->deleted,
            ]);
            Flash::set(
                'success',
                'Personal data erased — '
                . $eraseResult->deleted['comments'] . ' comment(s), '
                . $eraseResult->deleted['form_entries'] . ' form submission(s) removed.'
            );

            return $response->withHeader('Location', $back)->withStatus(302);
        })->setName('admin.tools.privacy.erase');
    })->add($perm)->add($middleware);
};
