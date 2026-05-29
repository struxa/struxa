<?php

declare(strict_types=1);

use App\Access\PermissionSlug;
use App\Event\Events;
use App\Event\MediaUploadedEvent;
use App\Flash;
use App\Http\Middleware\RequireCmsStaff;
use App\Http\Middleware\RequirePermission;
use App\Media\MediaCompressionSettings;
use App\Media\MediaDeletionService;
use App\Media\MediaMetadataValidator;
use App\Media\MediaRepository;
use App\Media\MediaStorage;
use App\Media\MediaUploadService;
use PHPAuth\Auth;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Settings;
use App\Settings\SettingsRepository;
use Slim\App;
use Slim\Exception\HttpNotFoundException;
use Slim\Routing\RouteContext;
use Slim\Views\Twig;

/**
 * @param callable(): array<string, mixed> $viewData
 */
return static function (App $app, Twig $twig, Auth $auth, \PDO $pdo, callable $viewData): void {
    $middleware = new RequireCmsStaff($auth, $pdo);
    $permMedia = new RequirePermission($pdo, [PermissionSlug::MANAGE_MEDIA]);
    $repo = new MediaRepository($pdo);
    $projectRoot = MediaUploadService::projectRoot();
    $uploadService = new MediaUploadService($repo, $projectRoot, MediaUploadService::maxBytesFromEnv());
    $deleteService = new MediaDeletionService($repo, $projectRoot);
    $metaValidator = new MediaMetadataValidator();

    $adminContext = static function () use ($viewData): array {
        return array_merge($viewData(), []);
    };

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
        $repo,
        $uploadService,
        $deleteService,
        $metaValidator,
        $cmsUserId,
        $pdo
    ): void {
        $perPage = 24;

        $group->get('/media', function (Request $request, Response $response) use ($twig, $adminContext, $withCmsUser, $repo, $perPage): Response {
            $qp = $request->getQueryParams();
            $q = isset($qp['q']) && is_string($qp['q']) ? trim($qp['q']) : '';
            $page = isset($qp['page']) ? max(1, (int) $qp['page']) : 1;
            $viewRaw = isset($qp['view']) && is_string($qp['view']) ? strtolower(trim($qp['view'])) : '';
            $mediaView = $viewRaw === 'list' ? 'list' : 'gallery';

            $total = $repo->countSearch($q);
            $rows = $repo->searchPaginated($q, $page, $perPage);
            $totalPages = $total > 0 ? (int) ceil($total / $perPage) : 1;
            if ($page > $totalPages && $total > 0) {
                $page = $totalPages;
                $rows = $repo->searchPaginated($q, $page, $perPage);
            }

            $stats = $repo->libraryStats();
            $compressCaps = MediaCompressionSettings::capabilities();

            return $twig->render($response, 'admin/media/list.twig', $withCmsUser($request, array_merge($adminContext(), [
                'admin_nav' => 'media',
                'media_rows' => $rows,
                'search_q' => $q,
                'media_view' => $mediaView,
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => $totalPages,
                'max_upload_mb' => (int) round(MediaUploadService::maxBytesFromEnv() / 1024 / 1024),
                'media_stats' => $stats,
                'media_compress_enabled' => MediaCompressionSettings::isEnabled(),
                'media_compress_caps' => $compressCaps,
                'media_compress_max_edge' => MediaCompressionSettings::maxEdgePx(),
            ])));
        })->setName('admin.media.index');

        $group->post('/media/compress-setting', function (Request $request, Response $response) use ($pdo): Response {
            $caps = MediaCompressionSettings::capabilities();
            if (!$caps['available']) {
                $response->getBody()->write(json_encode([
                    'ok' => false,
                    'error' => $caps['hint'],
                    'capabilities' => $caps,
                ]));

                return $response->withStatus(422)->withHeader('Content-Type', 'application/json; charset=utf-8');
            }

            $body = $request->getParsedBody();
            $body = is_array($body) ? $body : [];
            $enabled = isset($body['enabled']) && (string) $body['enabled'] === '1';

            (new SettingsRepository($pdo))->upsert(
                MediaCompressionSettings::SETTING_KEY,
                $enabled ? '1' : '0'
            );
            Settings::reload($pdo);

            $payload = [
                'ok' => true,
                'enabled' => MediaCompressionSettings::isEnabled(),
                'capabilities' => MediaCompressionSettings::capabilities(),
            ];
            $response->getBody()->write(json_encode($payload));

            return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
        })->setName('admin.media.compress_setting');

        $group->get('/media/api/images', function (Request $request, Response $response) use ($repo): Response {
            $rows = $repo->listImagesForPicker(400);
            $images = [];
            foreach ($rows as $r) {
                if (($r['public_url'] ?? '') === '') {
                    continue;
                }
                $images[] = [
                    'id' => $r['id'],
                    'url' => $r['public_url'],
                    'name' => $r['original_name'],
                ];
            }
            $response->getBody()->write(json_encode(['ok' => true, 'images' => $images]));

            return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
        })->setName('admin.media.api.images');

        $group->post('/media/api/upload', function (Request $request, Response $response) use ($repo, $uploadService, $cmsUserId): Response {
            $files = $request->getUploadedFiles();
            $file = is_array($files) && isset($files['file']) ? $files['file'] : null;
            if ($file === null) {
                $response->getBody()->write(json_encode(['ok' => false, 'error' => 'No file uploaded.']));

                return $response->withStatus(400)->withHeader('Content-Type', 'application/json; charset=utf-8');
            }
            $result = $uploadService->handleUpload($file, $cmsUserId($request));
            if ($result['ok'] !== true) {
                $response->getBody()->write(json_encode(['ok' => false, 'error' => $result['error']]));

                return $response->withStatus(422)->withHeader('Content-Type', 'application/json; charset=utf-8');
            }
            Events::dispatch(new MediaUploadedEvent((int) $result['id']));
            $media = $repo->findById((int) $result['id']);
            $url = '';
            if ($media !== null && MediaStorage::isSafeManagedWebPath($media->path)) {
                $url = $media->path;
            }
            $response->getBody()->write(json_encode([
                'ok' => true,
                'id' => (int) $result['id'],
                'url' => $url,
                'name' => $media?->originalName ?? '',
            ]));

            return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
        })->setName('admin.media.api.upload');

        $group->get('/media/upload', function (Request $request, Response $response) use ($twig, $adminContext, $withCmsUser, $uploadService): Response {
            return $twig->render($response, 'admin/media/upload.twig', $withCmsUser($request, array_merge($adminContext(), [
                'admin_nav' => 'media',
                'upload_error' => null,
                'max_mb' => (int) round(MediaUploadService::maxBytesFromEnv() / 1024 / 1024),
            ])));
        })->setName('admin.media.upload');

        $group->post('/media/upload', function (Request $request, Response $response) use ($twig, $adminContext, $withCmsUser, $uploadService, $cmsUserId): Response {
            $files = $request->getUploadedFiles();
            $file = is_array($files) && isset($files['file']) ? $files['file'] : null;
            if ($file === null) {
                Flash::set('error', 'No file was uploaded.');

                return $response
                    ->withHeader('Location', RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.media.upload'))
                    ->withStatus(302);
            }

            $result = $uploadService->handleUpload($file, $cmsUserId($request));
            if ($result['ok'] !== true) {
                return $twig->render($response, 'admin/media/upload.twig', $withCmsUser($request, array_merge($adminContext(), [
                    'admin_nav' => 'media',
                    'upload_error' => $result['error'],
                    'max_mb' => (int) round(MediaUploadService::maxBytesFromEnv() / 1024 / 1024),
                ])));
            }

            Events::dispatch(new MediaUploadedEvent((int) $result['id']));
            Flash::set('success', 'File uploaded.');
            $parser = RouteContext::fromRequest($request)->getRouteParser();

            return $response
                ->withHeader('Location', $parser->urlFor('admin.media.edit', ['id' => (string) $result['id']]))
                ->withStatus(302);
        })->setName('admin.media.store');

        $group->get('/media/{id:[0-9]+}/edit', function (Request $request, Response $response, array $args) use ($twig, $adminContext, $withCmsUser, $repo): Response {
            $id = (int) $args['id'];
            $media = $repo->findById($id);
            if ($media === null) {
                throw new HttpNotFoundException($request);
            }

            return $twig->render($response, 'admin/media/edit.twig', $withCmsUser($request, array_merge($adminContext(), [
                'admin_nav' => 'media',
                'media' => $media,
                'errors' => [],
            ])));
        })->setName('admin.media.edit');

        $group->post('/media/{id:[0-9]+}/edit', function (Request $request, Response $response, array $args) use ($twig, $adminContext, $withCmsUser, $repo, $metaValidator): Response {
            $id = (int) $args['id'];
            $media = $repo->findById($id);
            if ($media === null) {
                throw new HttpNotFoundException($request);
            }

            $body = $request->getParsedBody();
            $body = is_array($body) ? $body : [];
            $result = $metaValidator->validate($body);

            if ($result['errors'] !== []) {
                return $twig->render($response, 'admin/media/edit.twig', $withCmsUser($request, array_merge($adminContext(), [
                    'admin_nav' => 'media',
                    'media' => $media,
                    'errors' => $result['errors'],
                    'old' => $result['values'],
                ])));
            }

            $v = $result['values'];
            $repo->updateMetadata($id, $v['alt_text'], $v['title'], $v['caption']);
            Flash::set('success', 'Media updated.');

            return $response
                ->withHeader('Location', RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.media.edit', ['id' => (string) $id]))
                ->withStatus(302);
        })->setName('admin.media.update');

        $group->post('/media/{id:[0-9]+}/delete', function (Request $request, Response $response, array $args) use ($deleteService): Response {
            $id = (int) $args['id'];
            $deleteService->delete($id);
            Flash::set('success', 'Media removed.');

            return $response
                ->withHeader('Location', RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.media.index'))
                ->withStatus(302);
        })->setName('admin.media.delete');

        $group->post('/media/bulk-delete', function (Request $request, Response $response) use ($deleteService, $repo, $perPage): Response {
            $body = $request->getParsedBody();
            $body = is_array($body) ? $body : [];
            $raw = $body['ids'] ?? [];
            if (!is_array($raw)) {
                $raw = [];
            }

            $deleted = $deleteService->deleteMany($raw);
            if ($deleted > 0) {
                Flash::set('success', $deleted === 1 ? '1 file removed.' : $deleted . ' files removed.');
            } else {
                Flash::set('error', 'No files were selected.');
            }

            $returnQ = isset($body['return_q']) && is_string($body['return_q']) ? trim($body['return_q']) : '';
            $returnView = isset($body['return_view']) && is_string($body['return_view']) ? strtolower(trim($body['return_view'])) : '';
            $returnPage = isset($body['return_page']) && is_numeric($body['return_page']) ? max(1, (int) $body['return_page']) : 1;

            $total = $repo->countSearch($returnQ);
            $totalPages = $total > 0 ? (int) ceil($total / $perPage) : 1;
            $page = min($returnPage, max(1, $totalPages));

            $query = [];
            if ($returnQ !== '') {
                $query['q'] = $returnQ;
            }
            if ($returnView === 'list') {
                $query['view'] = 'list';
            }
            if ($page > 1) {
                $query['page'] = $page;
            }

            $url = RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.media.index');
            if ($query !== []) {
                $url .= '?' . http_build_query($query);
            }

            return $response
                ->withHeader('Location', $url)
                ->withStatus(302);
        })->setName('admin.media.bulk_delete');
    })->add($permMedia)->add($middleware);
};
