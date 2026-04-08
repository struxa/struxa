<?php

declare(strict_types=1);

use App\Access\PermissionSlug;
use App\Event\Events;
use App\Event\StorefrontCachesInvalidateEvent;
use App\Flash;
use App\Http\Middleware\RequireCmsStaff;
use App\Http\Middleware\RequirePermission;
use App\Seo\NotFoundLogRepository;
use App\Seo\RedirectRepository;
use App\Seo\SitemapOptions;
use App\Seo\SitemapService;
use App\Settings;
use App\Settings\SettingsRepository;
use App\Content\ContentEntryRepository;
use App\Page\PageRepository;
use PHPAuth\Auth;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Slim\Exception\HttpNotFoundException;
use Slim\Routing\RouteContext;
use Slim\Views\Twig;

/**
 * @param callable(): array<string, mixed> $viewData
 */
return static function (App $app, Twig $twig, Auth $auth, \PDO $pdo, callable $viewData): void {
    $middleware = new RequireCmsStaff($auth, $pdo);
    $perm = new RequirePermission($pdo, [PermissionSlug::MANAGE_SETTINGS]);
    $redirects = new RedirectRepository($pdo);
    $notFound = new NotFoundLogRepository($pdo);

    $adminContext = static fn (): array => array_merge($viewData(), []);
    $withCmsUser = static function (Request $request, array $data): array {
        /** @var array<string, mixed> $cmsUser */
        $cmsUser = $request->getAttribute('cms_user') ?? [];

        return array_merge($data, ['cms_user' => $cmsUser]);
    };

    $jsonResponse = static function (Response $response, array $payload, int $status = 200): Response {
        $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store');
    };

    $parseRedirectBody = static function (array $body): array {
        $errors = [];
        $from = trim((string) ($body['from_path'] ?? ''));
        if ($from === '') {
            $errors['from_path'] = 'From path is required.';
        }
        $from = $from !== '' ? RedirectRepository::normalizePath($from) : '';

        $to = trim((string) ($body['to_url'] ?? ''));
        if ($to === '') {
            $errors['to_url'] = 'Destination URL or path is required.';
        } elseif (!preg_match('#^https?://#i', $to) && !str_starts_with($to, '/')) {
            $errors['to_url'] = 'Use a full URL (https://…) or a path starting with /.';
        }

        $code = (int) ($body['status_code'] ?? 301);
        if (!in_array($code, [301, 302, 307, 308], true)) {
            $code = 301;
        }

        return [
            'errors' => $errors,
            'from_path' => $from,
            'to_url' => $to,
            'status_code' => $code,
        ];
    };

    $redirectListPerPage = 25;
    $notFoundListPerPage = 25;
    $paginateRedirects = static function (RedirectRepository $r, int $page, int $perPage): array {
        $total = $r->countAll();
        $totalPages = $total > 0 ? (int) ceil($total / $perPage) : 1;
        $page = min(max(1, $page), $totalPages);
        $offset = ($page - 1) * $perPage;
        $rows = $total > 0 ? $r->listOrderedPage($perPage, $offset) : [];

        return [
            'redirect_rows' => $rows,
            'redirect_list_page' => $page,
            'redirect_total' => $total,
            'redirect_total_pages' => $totalPages,
            'redirect_per_page' => $perPage,
        ];
    };

    $app->group('/admin', function (\Slim\Routing\RouteCollectorProxy $group) use (
        $twig,
        $adminContext,
        $withCmsUser,
        $redirects,
        $notFound,
        $parseRedirectBody,
        $redirectListPerPage,
        $notFoundListPerPage,
        $paginateRedirects,
        $pdo,
        $viewData,
        $jsonResponse
    ): void {
        $group->get('/seo/redirects', function (Request $request, Response $response) use ($twig, $adminContext, $withCmsUser, $redirects, $redirectListPerPage, $paginateRedirects): Response {
            $q = $request->getQueryParams();
            $prefill = isset($q['prefill']) && is_string($q['prefill']) ? trim($q['prefill']) : '';
            $page = isset($q['page']) ? max(1, (int) $q['page']) : 1;
            $p = $paginateRedirects($redirects, $page, $redirectListPerPage);

            return $twig->render($response, 'admin/seo/redirects.twig', $withCmsUser($request, array_merge($adminContext(), [
                'admin_nav' => 'seo_redirects',
                'form_errors' => [],
                'form_old' => ['from_path' => $prefill, 'to_url' => '', 'status_code' => '301'],
            ], $p)));
        })->setName('admin.seo.redirects');

        $group->post('/seo/redirects', function (Request $request, Response $response) use ($twig, $adminContext, $withCmsUser, $redirects, $parseRedirectBody, $redirectListPerPage, $paginateRedirects): Response {
            $body = $request->getParsedBody();
            $body = is_array($body) ? $body : [];
            $listPage = isset($body['list_page']) ? max(1, (int) $body['list_page']) : 1;
            $p = $paginateRedirects($redirects, $listPage, $redirectListPerPage);
            $parsed = $parseRedirectBody($body);
            if ($parsed['errors'] !== []) {
                return $twig->render($response, 'admin/seo/redirects.twig', $withCmsUser($request, array_merge($adminContext(), [
                    'admin_nav' => 'seo_redirects',
                    'form_errors' => $parsed['errors'],
                    'form_old' => [
                        'from_path' => (string) ($body['from_path'] ?? ''),
                        'to_url' => (string) ($body['to_url'] ?? ''),
                        'status_code' => (string) ($body['status_code'] ?? '301'),
                    ],
                ], $p)));
            }
            $redirects->upsertPath($parsed['from_path'], $parsed['to_url'], $parsed['status_code']);
            Flash::set('success', 'Redirect saved.');
            Events::dispatch(new StorefrontCachesInvalidateEvent('redirect_saved'));

            $rp = RouteContext::fromRequest($request)->getRouteParser();
            $loc = $rp->urlFor('admin.seo.redirects', [], $listPage > 1 ? ['page' => (string) $listPage] : []);

            return $response
                ->withHeader('Location', $loc)
                ->withStatus(302);
        })->setName('admin.seo.redirects.store');

        $group->get('/seo/redirects/{id:[0-9]+}/edit', function (Request $request, Response $response, array $args) use ($twig, $adminContext, $withCmsUser, $redirects): Response {
            $row = $redirects->findById((int) $args['id']);
            if ($row === null) {
                throw new HttpNotFoundException($request);
            }

            return $twig->render($response, 'admin/seo/redirect_edit.twig', $withCmsUser($request, array_merge($adminContext(), [
                'admin_nav' => 'seo_redirects',
                'redirect_row' => $row,
                'form_errors' => [],
            ])));
        })->setName('admin.seo.redirects.edit');

        $group->post('/seo/redirects/{id:[0-9]+}/edit', function (Request $request, Response $response, array $args) use ($twig, $adminContext, $withCmsUser, $redirects, $parseRedirectBody): Response {
            $id = (int) $args['id'];
            $row = $redirects->findById($id);
            if ($row === null) {
                throw new HttpNotFoundException($request);
            }
            $body = $request->getParsedBody();
            $body = is_array($body) ? $body : [];
            $parsed = $parseRedirectBody($body);
            if ($parsed['errors'] !== []) {
                return $twig->render($response, 'admin/seo/redirect_edit.twig', $withCmsUser($request, array_merge($adminContext(), [
                    'admin_nav' => 'seo_redirects',
                    'redirect_row' => $row,
                    'form_errors' => $parsed['errors'],
                ])));
            }
            $redirects->update($id, $parsed['from_path'], $parsed['to_url'], $parsed['status_code']);
            Flash::set('success', 'Redirect updated.');
            Events::dispatch(new StorefrontCachesInvalidateEvent('redirect_updated'));

            return $response
                ->withHeader('Location', RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.seo.redirects'))
                ->withStatus(302);
        })->setName('admin.seo.redirects.update');

        $group->post('/seo/redirects/{id:[0-9]+}/delete', function (Request $request, Response $response, array $args) use ($redirects): Response {
            $redirects->delete((int) $args['id']);
            Flash::set('success', 'Redirect removed.');
            Events::dispatch(new StorefrontCachesInvalidateEvent('redirect_deleted'));

            return $response
                ->withHeader('Location', RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.seo.redirects'))
                ->withStatus(302);
        })->setName('admin.seo.redirects.delete');

        $group->get('/seo/not-found', function (Request $request, Response $response) use ($twig, $adminContext, $withCmsUser, $notFound, $notFoundListPerPage): Response {
            $query = $request->getQueryParams();
            $page = isset($query['page']) && is_numeric($query['page']) ? max(1, (int) $query['page']) : 1;
            $total = $notFound->countAll();
            $totalPages = $total > 0 ? (int) ceil($total / $notFoundListPerPage) : 1;
            $page = min($page, $totalPages);
            $offset = ($page - 1) * $notFoundListPerPage;
            $rows = $total > 0 ? $notFound->listByHitsPage($notFoundListPerPage, $offset) : [];

            return $twig->render($response, 'admin/seo/not_found.twig', $withCmsUser($request, array_merge($adminContext(), [
                'admin_nav' => 'seo_not_found',
                'not_found_rows' => $rows,
                'not_found_list_page' => $page,
                'not_found_total' => $total,
                'not_found_total_pages' => $totalPages,
                'not_found_per_page' => $notFoundListPerPage,
            ])));
        })->setName('admin.seo.not_found');

        $group->get('/seo/not-found/{id:[0-9]+}/hits', function (Request $request, Response $response, array $args) use ($notFound, $jsonResponse): Response {
            $id = (int) $args['id'];
            $row = $notFound->findById($id);
            if ($row === null) {
                return $jsonResponse($response, ['ok' => false, 'error' => 'Log entry not found.'], 404);
            }
            $hits = $notFound->listHitsForLogId($id);

            return $jsonResponse($response, [
                'ok' => true,
                'path' => (string) ($row['path'] ?? ''),
                'hit_count' => (int) ($row['hit_count'] ?? 0),
                'hits' => $hits,
            ]);
        })->setName('admin.seo.not_found.hits');

        $group->post('/seo/not-found/{id:[0-9]+}/delete', function (Request $request, Response $response, array $args) use ($notFound): Response {
            $notFound->deleteById((int) $args['id']);
            Flash::set('success', '404 log entry removed.');
            $body = $request->getParsedBody();
            $body = is_array($body) ? $body : [];
            $returnPage = isset($body['return_page']) && is_numeric($body['return_page']) ? max(1, (int) $body['return_page']) : 1;
            $url = RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.seo.not_found');
            if ($returnPage > 1) {
                $url .= '?' . http_build_query(['page' => $returnPage]);
            }

            return $response
                ->withHeader('Location', $url)
                ->withStatus(302);
        })->setName('admin.seo.not_found.delete');

        $group->post('/seo/not-found/bulk-delete', function (Request $request, Response $response) use ($notFound, $notFoundListPerPage): Response {
            $body = $request->getParsedBody();
            $body = is_array($body) ? $body : [];
            $raw = $body['ids'] ?? [];
            if (!is_array($raw)) {
                $raw = [];
            }
            $deleted = $notFound->deleteByIds($raw);
            if ($deleted > 0) {
                Flash::set('success', $deleted === 1 ? 'Removed 1 log row.' : 'Removed ' . $deleted . ' log rows.');
            } else {
                Flash::set('success', 'Nothing was selected to remove.');
            }
            $returnPage = isset($body['return_page']) && is_numeric($body['return_page']) ? max(1, (int) $body['return_page']) : 1;
            $total = $notFound->countAll();
            $totalPages = $total > 0 ? (int) ceil($total / $notFoundListPerPage) : 1;
            $page = min($returnPage, max(1, $totalPages));
            $url = RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.seo.not_found');
            if ($page > 1) {
                $url .= '?' . http_build_query(['page' => $page]);
            }

            return $response
                ->withHeader('Location', $url)
                ->withStatus(302);
        })->setName('admin.seo.not_found.bulk_delete');

        $group->get('/seo/sitemap', function (Request $request, Response $response) use ($twig, $adminContext, $withCmsUser, $pdo, $viewData): Response {
            $siteUrl = rtrim((string) (($viewData())['site_url'] ?? ''), '/');
            $svc = new SitemapService($pdo, new PageRepository($pdo), new ContentEntryRepository($pdo));
            $opt = SitemapOptions::fromSettings();
            $urlCount = count($svc->collectUrls($siteUrl, $opt));

            return $twig->render($response, 'admin/seo/sitemap.twig', $withCmsUser($request, array_merge($adminContext(), [
                'admin_nav' => 'seo_sitemap',
                'sitemap_enabled' => SitemapOptions::sitemapPubliclyEnabled(),
                'sitemap_include_pages' => $opt->includePages,
                'sitemap_include_entries' => $opt->includeEntries,
                'sitemap_include_taxonomy_archives' => $opt->includeTaxonomyArchives,
                'sitemap_url_count' => $urlCount,
            ])));
        })->setName('admin.seo.sitemap');

        $group->post('/seo/sitemap', function (Request $request, Response $response) use ($pdo): Response {
            $body = $request->getParsedBody();
            $body = is_array($body) ? $body : [];
            $repo = new SettingsRepository($pdo);
            $repo->upsertMany([
                'sitemap_enabled' => !empty($body['sitemap_enabled']) ? '1' : '0',
                'sitemap_include_pages' => !empty($body['sitemap_include_pages']) ? '1' : '0',
                'sitemap_include_entries' => !empty($body['sitemap_include_entries']) ? '1' : '0',
                'sitemap_include_taxonomy_archives' => !empty($body['sitemap_include_taxonomy_archives']) ? '1' : '0',
            ], true);
            Settings::reload($pdo);
            Flash::set('success', 'Sitemap settings saved.');
            Events::dispatch(new StorefrontCachesInvalidateEvent('sitemap_settings'));

            return $response
                ->withHeader('Location', RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.seo.sitemap'))
                ->withStatus(302);
        })->setName('admin.seo.sitemap.save');
    })->add($perm)->add($middleware);
};

