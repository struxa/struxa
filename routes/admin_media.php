<?php

declare(strict_types=1);

use App\Access\PermissionSlug;
use App\Event\Events;
use App\Event\MediaUploadedEvent;
use App\Filter\FilterHook;
use App\Filter\Filters;
use App\Flash;
use App\Http\Middleware\RequireCmsStaff;
use App\Http\Middleware\RequirePermission;
use App\Media\MediaCompressionSettings;
use App\Media\MediaDeletionService;
use App\Media\MediaFolderFilter;
use App\Media\MediaFolderRepository;
use App\Media\MediaFolderSlugger;
use App\Media\MediaFolderValidator;
use App\Media\MediaLibraryListOptions;
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
    $folderRepo = new MediaFolderRepository($pdo);
    $folderValidator = new MediaFolderValidator();
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

    $parseUploadFolderId = static function (Request $request) use ($folderRepo): ?int {
        $body = $request->getParsedBody();
        if (!is_array($body) || !isset($body['folder_id'])) {
            return null;
        }
        $raw = trim((string) $body['folder_id']);
        if ($raw === '' || $raw === '0' || strtolower($raw) === 'unfiled') {
            return null;
        }
        if (!ctype_digit($raw)) {
            return null;
        }
        $id = (int) $raw;
        if ($id < 1 || !$folderRepo->existsId($id)) {
            return null;
        }

        return $id;
    };

    $folderFilterFromReturn = static function (array $body): MediaFolderFilter {
        $raw = isset($body['return_folder']) && is_string($body['return_folder']) ? trim($body['return_folder']) : '';
        if ($raw === '' || strtolower($raw) === 'all') {
            return new MediaFolderFilter(MediaFolderFilter::MODE_ALL);
        }
        if ($raw === '0' || strtolower($raw) === 'unfiled') {
            return new MediaFolderFilter(MediaFolderFilter::MODE_UNFILED);
        }
        if (ctype_digit($raw)) {
            $id = (int) $raw;
            if ($id > 0) {
                return new MediaFolderFilter(MediaFolderFilter::MODE_FOLDER, $id);
            }
        }

        return new MediaFolderFilter(MediaFolderFilter::MODE_ALL);
    };

    $mediaIndexQuery = static function (
        string $q,
        string $mediaView,
        MediaLibraryListOptions $listOpts,
        int $page,
        MediaFolderFilter $folderFilter,
    ): array {
        $query = [];
        if ($q !== '') {
            $query['q'] = $q;
        }
        if ($mediaView === 'list') {
            $query['view'] = 'list';
        }
        if ($listOpts->sort !== MediaLibraryListOptions::SORT_NEWEST) {
            $query['sort'] = $listOpts->sort;
        }
        if ($listOpts->perPage !== 24) {
            $query['per_page'] = $listOpts->perPage;
        }
        if ($page > 1) {
            $query['page'] = $page;
        }

        return array_merge($query, $folderFilter->toQueryParams());
    };

    $redirectToMediaIndex = static function (
        Request $request,
        Response $response,
        array $body,
        string $qOverride = '',
    ) use ($repo, $mediaIndexQuery, $folderFilterFromReturn): Response {
        $returnQ = $qOverride !== ''
            ? $qOverride
            : (isset($body['return_q']) && is_string($body['return_q']) ? trim($body['return_q']) : '');
        $returnView = isset($body['return_view']) && is_string($body['return_view']) ? strtolower(trim($body['return_view'])) : '';
        $returnPage = isset($body['return_page']) && is_numeric($body['return_page']) ? max(1, (int) $body['return_page']) : 1;
        $returnSort = isset($body['return_sort']) && is_string($body['return_sort']) ? $body['return_sort'] : MediaLibraryListOptions::SORT_NEWEST;
        $returnPerPage = isset($body['return_per_page']) ? (int) $body['return_per_page'] : 24;
        $listOpts = MediaLibraryListOptions::fromQueryParams([
            'sort' => $returnSort,
            'per_page' => (string) $returnPerPage,
        ]);
        $folderFilter = $folderFilterFromReturn($body);

        $total = $repo->countSearch($returnQ, $folderFilter);
        $totalPages = $total > 0 ? (int) ceil($total / $listOpts->perPage) : 1;
        $page = min($returnPage, max(1, $totalPages));

        $query = $mediaIndexQuery($returnQ, $returnView, $listOpts, $page, $folderFilter);
        $url = RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.media.index');
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }

        return $response->withHeader('Location', $url)->withStatus(302);
    };

    $app->group('/admin', function (\Slim\Routing\RouteCollectorProxy $group) use (
        $twig,
        $adminContext,
        $withCmsUser,
        $repo,
        $folderRepo,
        $folderValidator,
        $uploadService,
        $deleteService,
        $metaValidator,
        $cmsUserId,
        $pdo,
        $parseUploadFolderId,
        $mediaIndexQuery,
        $redirectToMediaIndex,
    ): void {
        $group->get('/media', function (Request $request, Response $response) use (
            $twig,
            $adminContext,
            $withCmsUser,
            $repo,
            $folderRepo,
            $mediaIndexQuery,
        ): Response {
            $qp = $request->getQueryParams();
            $q = isset($qp['q']) && is_string($qp['q']) ? trim($qp['q']) : '';
            $page = isset($qp['page']) ? max(1, (int) $qp['page']) : 1;
            $viewRaw = isset($qp['view']) && is_string($qp['view']) ? strtolower(trim($qp['view'])) : '';
            $mediaView = $viewRaw === 'list' ? 'list' : 'gallery';
            $listOpts = MediaLibraryListOptions::fromQueryParams($qp);
            $perPage = $listOpts->perPage;
            $folderFilter = MediaFolderFilter::fromQueryParams($qp);

            if ($folderFilter->mode === MediaFolderFilter::MODE_FOLDER && $folderFilter->folderId !== null) {
                if (!$folderRepo->existsId($folderFilter->folderId)) {
                    $folderFilter = new MediaFolderFilter(MediaFolderFilter::MODE_ALL);
                }
            }

            $total = $repo->countSearch($q, $folderFilter);
            $rows = $repo->searchPaginated($q, $page, $perPage, $listOpts->sort, $folderFilter);
            $totalPages = $total > 0 ? (int) ceil($total / $perPage) : 1;
            if ($page > $totalPages && $total > 0) {
                $page = $totalPages;
                $rows = $repo->searchPaginated($q, $page, $perPage, $listOpts->sort, $folderFilter);
            }

            $stats = $repo->libraryStats();
            $compressCaps = MediaCompressionSettings::capabilities();
            $folderTree = $folderRepo->treeWithCounts();
            $folderBreadcrumbs = [];
            $currentFolder = null;
            if ($folderFilter->mode === MediaFolderFilter::MODE_FOLDER && $folderFilter->folderId !== null) {
                $currentFolder = $folderRepo->findById($folderFilter->folderId);
                $folderBreadcrumbs = $folderRepo->breadcrumbChain($folderFilter->folderId);
            }

            $uploadFolderId = null;
            if ($folderFilter->mode === MediaFolderFilter::MODE_FOLDER && $folderFilter->folderId !== null) {
                $uploadFolderId = $folderFilter->folderId;
            }

            $mq = $mediaIndexQuery($q, $mediaView, $listOpts, 1, $folderFilter);
            unset($mq['page']);
            $mqAll = $mediaIndexQuery($q, $mediaView, $listOpts, 1, new MediaFolderFilter(MediaFolderFilter::MODE_ALL));
            unset($mqAll['page']);

            return $twig->render($response, 'admin/media/list.twig', $withCmsUser($request, array_merge($adminContext(), [
                'admin_nav' => 'media',
                'media_rows' => $rows,
                'search_q' => $q,
                'media_view' => $mediaView,
                'media_sort' => $listOpts->sort,
                'page' => $page,
                'per_page' => $perPage,
                'per_page_choices' => MediaLibraryListOptions::perPageChoices(),
                'total' => $total,
                'total_pages' => $totalPages,
                'max_upload_mb' => (int) round(MediaUploadService::maxBytesFromEnv() / 1024 / 1024),
                'media_stats' => $stats,
                'media_compress_enabled' => MediaCompressionSettings::isEnabled(),
                'media_compress_caps' => $compressCaps,
                'media_compress_max_edge' => MediaCompressionSettings::maxEdgePx(),
                'media_folder_tree' => $folderTree,
                'media_folder_filter' => $folderFilter,
                'media_folder_breadcrumbs' => $folderBreadcrumbs,
                'media_current_folder' => $currentFolder,
                'media_unfiled_count' => $folderRepo->countUnfiledMedia(),
                'media_upload_folder_id' => $uploadFolderId,
                'media_mq_base' => $mq,
                'media_mq_all' => $mqAll,
                'media_folder_options' => $folderRepo->optionsForSelect(),
            ])));
        })->setName('admin.media.index');

        $group->post('/media/folders', function (Request $request, Response $response) use (
            $folderRepo,
            $folderValidator,
            $redirectToMediaIndex,
        ): Response {
            $body = $request->getParsedBody();
            $body = is_array($body) ? $body : [];
            $result = $folderValidator->validate($body, null, $folderRepo);
            if ($result['errors'] !== []) {
                Flash::set('error', implode(' ', $result['errors']));

                return $redirectToMediaIndex($request, $response, $body);
            }

            $v = $result['values'];
            $slug = MediaFolderSlugger::ensureUnique($folderRepo, $v['parent_id'], (string) $v['slug']);
            $folderRepo->insert((string) $v['name'], $slug, $v['parent_id'], $folderRepo->nextSortOrder($v['parent_id']));
            Flash::set('success', 'Folder created.');

            $body['return_folder'] = (string) ($v['parent_id'] ?? '');
            if ($v['parent_id'] === null && isset($body['return_folder'])) {
                unset($body['return_folder']);
            }

            return $redirectToMediaIndex($request, $response, $body);
        })->setName('admin.media.folders.create');

        $group->post('/media/folders/{id:[0-9]+}/edit', function (Request $request, Response $response, array $args) use (
            $folderRepo,
            $folderValidator,
            $redirectToMediaIndex,
        ): Response {
            $id = (int) $args['id'];
            $folder = $folderRepo->findById($id);
            if ($folder === null) {
                throw new HttpNotFoundException($request);
            }

            $body = $request->getParsedBody();
            $body = is_array($body) ? $body : [];
            if (!isset($body['parent_id'])) {
                $body['parent_id'] = $folder->parentId !== null ? (string) $folder->parentId : '';
            }
            $result = $folderValidator->validate($body, $id, $folderRepo);
            if ($result['errors'] !== []) {
                Flash::set('error', implode(' ', $result['errors']));

                return $redirectToMediaIndex($request, $response, $body);
            }

            $v = $result['values'];
            $slug = MediaFolderSlugger::ensureUnique($folderRepo, $v['parent_id'], (string) $v['slug'], $id);
            $folderRepo->update($id, (string) $v['name'], $slug, $v['parent_id'], $folder->sortOrder);
            Flash::set('success', 'Folder updated.');

            return $redirectToMediaIndex($request, $response, $body);
        })->setName('admin.media.folders.edit');

        $group->post('/media/folders/{id:[0-9]+}/delete', function (Request $request, Response $response, array $args) use (
            $folderRepo,
            $redirectToMediaIndex,
        ): Response {
            $id = (int) $args['id'];
            if (!$folderRepo->existsId($id)) {
                throw new HttpNotFoundException($request);
            }

            $body = $request->getParsedBody();
            $body = is_array($body) ? $body : [];
            $folderRepo->deleteById($id);
            Flash::set('success', 'Folder deleted. Files inside are now unfiled.');

            if (isset($body['return_folder']) && (string) $body['return_folder'] === (string) $id) {
                $body['return_folder'] = '';
            }

            return $redirectToMediaIndex($request, $response, $body);
        })->setName('admin.media.folders.delete');

        $group->post('/media/bulk-move', function (Request $request, Response $response) use ($repo, $folderRepo, $redirectToMediaIndex): Response {
            $body = $request->getParsedBody();
            $body = is_array($body) ? $body : [];
            $raw = $body['ids'] ?? [];
            if (!is_array($raw)) {
                $raw = [];
            }

            $targetFolderId = null;
            $targetRaw = isset($body['target_folder_id']) ? trim((string) $body['target_folder_id']) : '';
            if ($targetRaw !== '' && $targetRaw !== '0' && strtolower($targetRaw) !== 'unfiled') {
                if (ctype_digit($targetRaw)) {
                    $fid = (int) $targetRaw;
                    if ($fid > 0 && $folderRepo->existsId($fid)) {
                        $targetFolderId = $fid;
                    }
                }
            }

            $moved = $repo->moveManyToFolder($raw, $targetFolderId);
            if ($moved > 0) {
                Flash::set('success', $moved === 1 ? '1 file moved.' : $moved . ' files moved.');
            } else {
                Flash::set('error', 'No files were selected.');
            }

            return $redirectToMediaIndex($request, $response, $body);
        })->setName('admin.media.bulk_move');

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

        $group->post('/media/api/upload', function (Request $request, Response $response) use ($repo, $uploadService, $cmsUserId, $parseUploadFolderId): Response {
            $files = $request->getUploadedFiles();
            $file = is_array($files) && isset($files['file']) ? $files['file'] : null;
            if ($file === null) {
                $response->getBody()->write(json_encode(['ok' => false, 'error' => 'No file uploaded.']));

                return $response->withStatus(400)->withHeader('Content-Type', 'application/json; charset=utf-8');
            }
            $uploadMeta = Filters::apply(FilterHook::MEDIA_UPLOAD, [
                'filename' => (string) ($file->getClientFilename() ?? ''),
                'size' => $file->getSize(),
                'mime' => $file->getClientMediaType(),
                'allowed' => true,
            ], ['source' => 'admin']);
            if (is_array($uploadMeta) && ($uploadMeta['allowed'] ?? true) === false) {
                $err = is_string($uploadMeta['block_message'] ?? null) && trim((string) $uploadMeta['block_message']) !== ''
                    ? trim((string) $uploadMeta['block_message'])
                    : 'Upload blocked.';
                $response->getBody()->write(json_encode(['ok' => false, 'error' => $err]));

                return $response->withStatus(422)->withHeader('Content-Type', 'application/json; charset=utf-8');
            }
            $folderId = $parseUploadFolderId($request);
            $result = $uploadService->handleUpload($file, $cmsUserId($request), $folderId);
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

        $group->get('/media/upload', function (Request $request, Response $response): Response {
            return $response
                ->withHeader('Location', RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.media.index'))
                ->withStatus(301);
        });

        $group->get('/media/{id:[0-9]+}/edit', function (Request $request, Response $response, array $args) use ($twig, $adminContext, $withCmsUser, $repo, $folderRepo): Response {
            $id = (int) $args['id'];
            $media = $repo->findById($id);
            if ($media === null) {
                throw new HttpNotFoundException($request);
            }

            return $twig->render($response, 'admin/media/edit.twig', $withCmsUser($request, array_merge($adminContext(), [
                'admin_nav' => 'media',
                'media' => $media,
                'media_folder_options' => $folderRepo->optionsForSelect(),
                'errors' => [],
            ])));
        })->setName('admin.media.edit');

        $group->post('/media/{id:[0-9]+}/edit', function (Request $request, Response $response, array $args) use ($twig, $adminContext, $withCmsUser, $repo, $folderRepo, $metaValidator): Response {
            $id = (int) $args['id'];
            $media = $repo->findById($id);
            if ($media === null) {
                throw new HttpNotFoundException($request);
            }

            $body = $request->getParsedBody();
            $body = is_array($body) ? $body : [];
            $result = $metaValidator->validate($body);
            $folderOptions = $folderRepo->optionsForSelect();

            $folderId = null;
            $folderRaw = isset($body['folder_id']) ? trim((string) $body['folder_id']) : '';
            if ($folderRaw !== '' && $folderRaw !== '0' && strtolower($folderRaw) !== 'unfiled') {
                if (!ctype_digit($folderRaw)) {
                    $result['errors']['folder_id'] = 'Invalid folder.';
                } else {
                    $fid = (int) $folderRaw;
                    if ($fid < 1 || !$folderRepo->existsId($fid)) {
                        $result['errors']['folder_id'] = 'Folder not found.';
                    } else {
                        $folderId = $fid;
                    }
                }
            }

            if ($result['errors'] !== []) {
                return $twig->render($response, 'admin/media/edit.twig', $withCmsUser($request, array_merge($adminContext(), [
                    'admin_nav' => 'media',
                    'media' => $media,
                    'media_folder_options' => $folderOptions,
                    'errors' => $result['errors'],
                    'old' => array_merge($result['values'], [
                        'folder_id' => $folderRaw !== '' ? $folderRaw : 'unfiled',
                    ]),
                ])));
            }

            $v = $result['values'];
            $repo->updateMetadata($id, $v['alt_text'], $v['title'], $v['caption']);
            $repo->updateFolderId($id, $folderId);
            Flash::set('success', 'Media updated.');

            return $response
                ->withHeader('Location', RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.media.edit', ['id' => (string) $id]))
                ->withStatus(302);
        })->setName('admin.media.update');

        $group->post('/media/{id:[0-9]+}/delete', function (Request $request, Response $response, array $args) use ($deleteService, $cmsUserId): Response {
            $id = (int) $args['id'];
            $deleteService->trash($id, $cmsUserId($request));
            Flash::set('success', 'Media moved to trash.');

            return $response
                ->withHeader('Location', RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.media.index'))
                ->withStatus(302);
        })->setName('admin.media.delete');

        $group->post('/media/bulk-delete', function (Request $request, Response $response) use ($deleteService, $redirectToMediaIndex): Response {
            $body = $request->getParsedBody();
            $body = is_array($body) ? $body : [];
            $raw = $body['ids'] ?? [];
            if (!is_array($raw)) {
                $raw = [];
            }

            $deleted = $deleteService->deleteMany($raw);
            if ($deleted > 0) {
                Flash::set('success', $deleted === 1 ? '1 file moved to trash.' : $deleted . ' files moved to trash.');
            } else {
                Flash::set('error', 'No files were selected.');
            }

            return $redirectToMediaIndex($request, $response, $body);
        })->setName('admin.media.bulk_delete');
    })->add($permMedia)->add($middleware);
};
