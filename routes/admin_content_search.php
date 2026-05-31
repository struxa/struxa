<?php

declare(strict_types=1);

use App\Access\PermissionService;
use App\Access\PermissionSlug;
use App\Http\Middleware\RequireCmsStaff;
use App\Http\Middleware\RequirePermission;
use App\Media\MediaRepository;
use App\Search\AdminContentSearchService;
use App\Search\ContentSearchService;
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
    $perm = new RequirePermission($pdo, [
        PermissionSlug::MANAGE_PAGES,
        PermissionSlug::MANAGE_CONTENT_TYPES,
        PermissionSlug::CREATE_CONTENT,
        PermissionSlug::EDIT_CONTENT,
        PermissionSlug::REVIEW_CONTENT,
        PermissionSlug::PUBLISH_CONTENT,
        PermissionSlug::MANAGE_MEDIA,
    ]);
    $searchService = new AdminContentSearchService($pdo, new MediaRepository($pdo));
    $permChecker = new PermissionService();

    $adminContext = static fn (): array => array_merge($viewData(), []);
    $withCmsUser = static function (Request $request, array $data): array {
        /** @var array<string, mixed> $cmsUser */
        $cmsUser = $request->getAttribute('cms_user') ?? [];

        return array_merge($data, ['cms_user' => $cmsUser]);
    };

    $searchScope = static function (Request $request) use ($permChecker, $pdo): array {
        /** @var array<string, mixed> $cmsUser */
        $cmsUser = $request->getAttribute('cms_user') ?? [];
        $userId = isset($cmsUser['id']) ? (int) $cmsUser['id'] : 0;
        if ($userId < 1) {
            return ['search_pages' => false, 'search_entries' => false, 'search_media' => false];
        }

        return [
            'search_pages' => $permChecker->userHas($pdo, $userId, PermissionSlug::MANAGE_PAGES),
            'search_entries' => $permChecker->userHasAny($pdo, $userId, [
                PermissionSlug::MANAGE_CONTENT_TYPES,
                PermissionSlug::CREATE_CONTENT,
                PermissionSlug::EDIT_CONTENT,
                PermissionSlug::REVIEW_CONTENT,
                PermissionSlug::PUBLISH_CONTENT,
            ]),
            'search_media' => $permChecker->userHas($pdo, $userId, PermissionSlug::MANAGE_MEDIA),
        ];
    };

    $resolveHitUrls = static function (array $hits, $parser): array {
        foreach ($hits as &$hit) {
            if ($hit['kind'] === 'page') {
                $hit['edit_url'] = $parser->urlFor('admin.pages.edit', ['id' => (string) $hit['id']]);
            } elseif ($hit['kind'] === 'entry') {
                $typeId = (int) ($hit['type_id'] ?? 0);
                if ($typeId > 0) {
                    $hit['edit_url'] = $parser->urlFor(
                        'admin.content_types.entries.edit',
                        ['id' => (string) $typeId, 'entryId' => (string) $hit['id']]
                    );
                }
            } elseif ($hit['kind'] === 'media') {
                $hit['edit_url'] = $parser->urlFor('admin.media.edit', ['id' => (string) $hit['id']]);
            }
        }
        unset($hit);

        return $hits;
    };

    $app->get('/admin/search', function (Request $request, Response $response) use (
        $twig,
        $adminContext,
        $withCmsUser,
        $searchService,
        $searchScope,
        $resolveHitUrls
    ): Response {
        $parser = RouteContext::fromRequest($request)->getRouteParser();
        $scope = $searchScope($request);

        $q = ContentSearchService::sanitizeQuery((string) ($request->getQueryParams()['q'] ?? ''));
        $page = isset($request->getQueryParams()['page']) && ctype_digit((string) $request->getQueryParams()['page'])
            ? max(1, (int) $request->getQueryParams()['page'])
            : 1;

        $result = $searchService->search($q, $scope, $page, AdminContentSearchService::PER_PAGE_DEFAULT);
        $result['hits'] = $resolveHitUrls($result['hits'], $parser);

        return $twig->render($response, 'admin/search/index.twig', $withCmsUser($request, array_merge($adminContext(), [
            'admin_nav' => 'admin_content_search',
            'search_query' => $q,
            'search_result' => $result,
            'search_can_pages' => $scope['search_pages'],
            'search_can_entries' => $scope['search_entries'],
            'search_can_media' => $scope['search_media'],
        ])));
    })->setName('admin.content.search')->add($perm)->add($middleware);

    $app->get('/admin/search/suggest', function (Request $request, Response $response) use (
        $searchService,
        $searchScope,
        $resolveHitUrls
    ): Response {
        $parser = RouteContext::fromRequest($request)->getRouteParser();
        $q = ContentSearchService::sanitizeQuery((string) ($request->getQueryParams()['q'] ?? ''));
        $scope = $searchScope($request);

        $hits = $resolveHitUrls($searchService->suggest($q, $scope), $parser);
        $items = [];
        foreach ($hits as $hit) {
            if (($hit['edit_url'] ?? '') === '') {
                continue;
            }
            $group = match ($hit['kind']) {
                'page' => 'Pages',
                'entry' => (string) ($hit['type_name'] ?? 'Entries'),
                'media' => 'Media',
                default => 'Content',
            };
            $meta = match ($hit['kind']) {
                'page' => (string) ($hit['status'] ?? ''),
                'entry' => (string) ($hit['status'] ?? ''),
                'media' => (string) ($hit['mime_type'] ?? 'file'),
                default => '',
            };
            $items[] = [
                'label' => (string) ($hit['title'] ?? ''),
                'href' => (string) $hit['edit_url'],
                'group' => $group,
                'meta' => $meta,
            ];
        }

        $allUrl = $q !== '' ? $parser->urlFor('admin.content.search') . '?q=' . rawurlencode($q) : $parser->urlFor('admin.content.search');
        if ($q !== '' && $items !== []) {
            array_unshift($items, [
                'label' => 'View all results for "' . $q . '"',
                'href' => $allUrl,
                'group' => 'Search',
                'meta' => '',
            ]);
        }

        $payload = json_encode(['ok' => true, 'query' => $q, 'items' => $items], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $response->getBody()->write($payload);

        return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
    })->setName('admin.content.search.suggest')->add($perm)->add($middleware);
};
