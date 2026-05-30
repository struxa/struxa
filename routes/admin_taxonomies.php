<?php

declare(strict_types=1);

use App\Access\PermissionSlug;
use App\Content\ContentTypeRepository;
use App\Event\Events;
use App\Event\StorefrontCachesInvalidateEvent;
use App\Flash;
use App\Http\Middleware\RequireCmsStaff;
use App\Http\Middleware\RequirePermission;
use App\Taxonomy\TaxonomyRepository;
use App\Taxonomy\TaxonomyTermRepository;
use App\Taxonomy\TaxonomyTermSlugger;
use App\Taxonomy\TaxonomyTermTree;
use App\Taxonomy\TaxonomyTermValidator;
use App\Taxonomy\TaxonomyType;
use App\Taxonomy\TaxonomyValidator;
use App\Media\MediaRepository;
use App\Seo\SeoFormParser;
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
    $permTax = new RequirePermission($pdo, [PermissionSlug::MANAGE_TAXONOMIES]);
    $types = new ContentTypeRepository($pdo);
    $tax = new TaxonomyRepository($pdo);
    $terms = new TaxonomyTermRepository($pdo);
    $mediaRepo = new MediaRepository($pdo);
    $taxValidator = new TaxonomyValidator();
    $termValidator = new TaxonomyTermValidator();

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
        $types,
        $tax,
        $terms,
        $taxValidator,
        $termValidator,
        $mediaRepo
    ): void {
        $group->get('/content-types/{id:[0-9]+}/taxonomies', function (Request $request, Response $response, array $args) use ($twig, $adminContext, $withCmsUser, $types, $tax): Response {
            $id = (int) $args['id'];
            $t = $types->findById($id);
            if ($t === null) {
                throw new HttpNotFoundException($request);
            }

            return $twig->render($response, 'admin/content/taxonomies/index.twig', $withCmsUser($request, array_merge($adminContext(), [
                'admin_nav' => 'content_types',
                'content_type' => $t,
                'taxonomies' => $tax->forContentTypeOrdered($id),
            ])));
        })->setName('admin.content_types.taxonomies.index');

        $group->get('/content-types/{id:[0-9]+}/taxonomies/new', function (Request $request, Response $response, array $args) use ($twig, $adminContext, $withCmsUser, $types): Response {
            $id = (int) $args['id'];
            $t = $types->findById($id);
            if ($t === null) {
                throw new HttpNotFoundException($request);
            }

            return $twig->render($response, 'admin/content/taxonomies/form.twig', $withCmsUser($request, array_merge($adminContext(), [
                'admin_nav' => 'content_types',
                'content_type' => $t,
                'taxonomy' => null,
                'errors' => [],
                'old' => [
                    'name' => '',
                    'slug' => '',
                    'description' => '',
                    'taxonomy_type' => TaxonomyType::CUSTOM,
                    'is_hierarchical' => false,
                ],
            ])));
        })->setName('admin.content_types.taxonomies.new');

        $group->post('/content-types/{id:[0-9]+}/taxonomies/new', function (Request $request, Response $response, array $args) use ($twig, $adminContext, $withCmsUser, $types, $tax, $taxValidator): Response {
            $id = (int) $args['id'];
            $t = $types->findById($id);
            if ($t === null) {
                throw new HttpNotFoundException($request);
            }
            $body = $request->getParsedBody();
            $body = is_array($body) ? $body : [];
            $result = $taxValidator->validate($body, $id, null, $tax);
            if ($result['errors'] !== []) {
                return $twig->render($response, 'admin/content/taxonomies/form.twig', $withCmsUser($request, array_merge($adminContext(), [
                    'admin_nav' => 'content_types',
                    'content_type' => $t,
                    'taxonomy' => null,
                    'errors' => $result['errors'],
                    'old' => $result['values'],
                ])));
            }
            $v = $result['values'];
            $tax->insert($id, $v['name'], $v['slug'], $v['description'], $v['taxonomy_type'], $v['is_hierarchical']);
            Flash::set('success', 'Taxonomy created.');
            Events::dispatch(new StorefrontCachesInvalidateEvent('taxonomy_created'));

            return $response
                ->withHeader('Location', RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.content_types.taxonomies.index', ['id' => (string) $id]))
                ->withStatus(302);
        })->setName('admin.content_types.taxonomies.store');

        $group->get('/content-types/{id:[0-9]+}/taxonomies/{taxId:[0-9]+}/edit', function (Request $request, Response $response, array $args) use ($twig, $adminContext, $withCmsUser, $types, $tax): Response {
            $id = (int) $args['id'];
            $taxId = (int) $args['taxId'];
            $t = $types->findById($id);
            if ($t === null) {
                throw new HttpNotFoundException($request);
            }
            $tx = $tax->findById($taxId);
            if ($tx === null || $tx->contentTypeId !== $id) {
                throw new HttpNotFoundException($request);
            }

            return $twig->render($response, 'admin/content/taxonomies/form.twig', $withCmsUser($request, array_merge($adminContext(), [
                'admin_nav' => 'content_types',
                'content_type' => $t,
                'taxonomy' => $tx,
                'errors' => [],
                'old' => null,
            ])));
        })->setName('admin.content_types.taxonomies.edit');

        $group->post('/content-types/{id:[0-9]+}/taxonomies/{taxId:[0-9]+}/edit', function (Request $request, Response $response, array $args) use ($twig, $adminContext, $withCmsUser, $types, $tax, $taxValidator): Response {
            $id = (int) $args['id'];
            $taxId = (int) $args['taxId'];
            $t = $types->findById($id);
            if ($t === null) {
                throw new HttpNotFoundException($request);
            }
            $tx = $tax->findById($taxId);
            if ($tx === null || $tx->contentTypeId !== $id) {
                throw new HttpNotFoundException($request);
            }
            $body = $request->getParsedBody();
            $body = is_array($body) ? $body : [];
            $result = $taxValidator->validate($body, $id, $taxId, $tax);
            if ($result['errors'] !== []) {
                return $twig->render($response, 'admin/content/taxonomies/form.twig', $withCmsUser($request, array_merge($adminContext(), [
                    'admin_nav' => 'content_types',
                    'content_type' => $t,
                    'taxonomy' => $tx,
                    'errors' => $result['errors'],
                    'old' => $result['values'],
                ])));
            }
            $v = $result['values'];
            $tax->update($taxId, $v['name'], $v['slug'], $v['description'], $v['taxonomy_type'], $v['is_hierarchical']);
            Flash::set('success', 'Taxonomy updated.');
            Events::dispatch(new StorefrontCachesInvalidateEvent('taxonomy_updated'));

            return $response
                ->withHeader('Location', RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.content_types.taxonomies.index', ['id' => (string) $id]))
                ->withStatus(302);
        })->setName('admin.content_types.taxonomies.update');

        $group->post('/content-types/{id:[0-9]+}/taxonomies/{taxId:[0-9]+}/delete', function (Request $request, Response $response, array $args) use ($tax): Response {
            $id = (int) $args['id'];
            $taxId = (int) $args['taxId'];
            if (!$tax->belongsToType($taxId, $id)) {
                throw new HttpNotFoundException($request);
            }
            $tax->delete($taxId);
            Flash::set('success', 'Taxonomy deleted.');
            Events::dispatch(new StorefrontCachesInvalidateEvent('taxonomy_deleted'));

            return $response
                ->withHeader('Location', RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.content_types.taxonomies.index', ['id' => (string) $id]))
                ->withStatus(302);
        })->setName('admin.content_types.taxonomies.delete');

        /* —— Terms —— */
        $group->get('/content-types/{id:[0-9]+}/taxonomies/{taxId:[0-9]+}/terms', function (Request $request, Response $response, array $args) use ($twig, $adminContext, $withCmsUser, $types, $tax, $terms): Response {
            $id = (int) $args['id'];
            $taxId = (int) $args['taxId'];
            $t = $types->findById($id);
            if ($t === null) {
                throw new HttpNotFoundException($request);
            }
            $tx = $tax->findById($taxId);
            if ($tx === null || $tx->contentTypeId !== $id) {
                throw new HttpNotFoundException($request);
            }
            $flat = $terms->forTaxonomyOrdered($taxId);
            $rows = TaxonomyTermTree::rowsWithDepth($flat, $tx->isHierarchical);

            return $twig->render($response, 'admin/content/taxonomies/terms/index.twig', $withCmsUser($request, array_merge($adminContext(), [
                'admin_nav' => 'content_types',
                'content_type' => $t,
                'taxonomy' => $tx,
                'term_rows' => $rows,
            ])));
        })->setName('admin.content_types.taxonomies.terms.index');

        $group->get('/content-types/{id:[0-9]+}/taxonomies/{taxId:[0-9]+}/terms/new', function (Request $request, Response $response, array $args) use ($twig, $adminContext, $withCmsUser, $types, $tax, $terms, $mediaRepo): Response {
            $id = (int) $args['id'];
            $taxId = (int) $args['taxId'];
            $t = $types->findById($id);
            if ($t === null) {
                throw new HttpNotFoundException($request);
            }
            $tx = $tax->findById($taxId);
            if ($tx === null || $tx->contentTypeId !== $id) {
                throw new HttpNotFoundException($request);
            }

            return $twig->render($response, 'admin/content/taxonomies/terms/form.twig', $withCmsUser($request, array_merge($adminContext(), [
                'admin_nav' => 'content_types',
                'content_type' => $t,
                'taxonomy' => $tx,
                'term' => null,
                'parent_options' => $terms->forTaxonomyOrdered($taxId),
                'errors' => [],
                'seo_media_select' => $mediaRepo->listImagesForPicker(200),
                'old' => [
                    'name' => '',
                    'slug' => '',
                    'description' => '',
                    'parent_id' => '',
                    'sort_order' => (string) $terms->nextSortOrder($taxId),
                ],
            ])));
        })->setName('admin.content_types.taxonomies.terms.new');

        $group->post('/content-types/{id:[0-9]+}/taxonomies/{taxId:[0-9]+}/terms/new', function (Request $request, Response $response, array $args) use ($twig, $adminContext, $withCmsUser, $types, $tax, $terms, $termValidator, $mediaRepo): Response {
            $id = (int) $args['id'];
            $taxId = (int) $args['taxId'];
            $t = $types->findById($id);
            if ($t === null) {
                throw new HttpNotFoundException($request);
            }
            $tx = $tax->findById($taxId);
            if ($tx === null || $tx->contentTypeId !== $id) {
                throw new HttpNotFoundException($request);
            }
            $body = $request->getParsedBody();
            $body = is_array($body) ? $body : [];
            $result = $termValidator->validate($body, $taxId, null, $tx, $terms);
            $seo = SeoFormParser::parseTerm($body, $mediaRepo);
            $allErr = array_merge($result['errors'], $seo['errors']);
            if ($allErr !== []) {
                return $twig->render($response, 'admin/content/taxonomies/terms/form.twig', $withCmsUser($request, array_merge($adminContext(), [
                    'admin_nav' => 'content_types',
                    'content_type' => $t,
                    'taxonomy' => $tx,
                    'term' => null,
                    'parent_options' => $terms->forTaxonomyOrdered($taxId),
                    'errors' => $allErr,
                    'old' => array_merge($result['values'], [
                        'seo_title' => trim((string) ($body['seo_title'] ?? '')),
                        'seo_description' => trim((string) ($body['seo_description'] ?? '')),
                        'canonical_url' => trim((string) ($body['canonical_url'] ?? '')),
                        'seo_noindex' => !empty($body['seo_noindex']),
                        'og_title' => trim((string) ($body['og_title'] ?? '')),
                        'og_description' => trim((string) ($body['og_description'] ?? '')),
                        'og_image_id' => trim((string) ($body['og_image_id'] ?? '')),
                        'twitter_title' => trim((string) ($body['twitter_title'] ?? '')),
                        'twitter_description' => trim((string) ($body['twitter_description'] ?? '')),
                        'twitter_image_id' => trim((string) ($body['twitter_image_id'] ?? '')),
                        'schema_json' => (string) ($body['schema_json'] ?? ''),
                    ]),
                    'seo_media_select' => $mediaRepo->listImagesForPicker(200),
                ])));
            }
            $v = $result['values'];
            $slug = TaxonomyTermSlugger::ensureUnique($terms, $taxId, $v['slug']);
            $terms->insert(
                $taxId,
                $v['name'],
                $slug,
                $v['description'],
                $v['parent_id'],
                $v['sort_order'],
                $seo['seo_title'],
                $seo['seo_description'],
                $seo['focus_keyphrase'],
                $seo['canonical_url'],
                $seo['seo_noindex'],
                $seo['og_title'],
                $seo['og_description'],
                $seo['og_image_id'],
                $seo['twitter_title'],
                $seo['twitter_description'],
                $seo['twitter_image_id'],
                $seo['schema_json']
            );
            Flash::set('success', 'Term created.');
            Events::dispatch(new StorefrontCachesInvalidateEvent('taxonomy_term_created'));

            return $response
                ->withHeader('Location', RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.content_types.taxonomies.terms.index', ['id' => (string) $id, 'taxId' => (string) $taxId]))
                ->withStatus(302);
        })->setName('admin.content_types.taxonomies.terms.store');

        $group->post('/content-types/{id:[0-9]+}/taxonomies/{taxId:[0-9]+}/terms/quick', function (Request $request, Response $response, array $args) use ($types, $tax, $terms): Response {
            $id = (int) $args['id'];
            $taxId = (int) $args['taxId'];
            $t = $types->findById($id);
            if ($t === null) {
                throw new HttpNotFoundException($request);
            }
            $tx = $tax->findById($taxId);
            if ($tx === null || $tx->contentTypeId !== $id) {
                throw new HttpNotFoundException($request);
            }
            $body = $request->getParsedBody();
            $body = is_array($body) ? $body : [];
            $name = trim((string) ($body['name'] ?? ''));
            if ($name === '' || mb_strlen($name) > 191) {
                Flash::set('error', 'Enter a valid term name.');

                return $response
                    ->withHeader('Location', RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.content_types.taxonomies.terms.index', ['id' => (string) $id, 'taxId' => (string) $taxId]))
                    ->withStatus(302);
            }
            $base = TaxonomyTermSlugger::slugify($name);
            $slug = TaxonomyTermSlugger::ensureUnique($terms, $taxId, $base);
            $sort = $terms->nextSortOrder($taxId);
            $terms->insert($taxId, $name, $slug, null, null, $sort);
            Flash::set('success', 'Term added.');
            Events::dispatch(new StorefrontCachesInvalidateEvent('taxonomy_term_added'));

            return $response
                ->withHeader('Location', RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.content_types.taxonomies.terms.index', ['id' => (string) $id, 'taxId' => (string) $taxId]))
                ->withStatus(302);
        })->setName('admin.content_types.taxonomies.terms.quick');

        $group->get('/content-types/{id:[0-9]+}/taxonomies/{taxId:[0-9]+}/terms/{termId:[0-9]+}/edit', function (Request $request, Response $response, array $args) use ($twig, $adminContext, $withCmsUser, $types, $tax, $terms, $mediaRepo): Response {
            $id = (int) $args['id'];
            $taxId = (int) $args['taxId'];
            $termId = (int) $args['termId'];
            $t = $types->findById($id);
            if ($t === null) {
                throw new HttpNotFoundException($request);
            }
            $tx = $tax->findById($taxId);
            if ($tx === null || $tx->contentTypeId !== $id) {
                throw new HttpNotFoundException($request);
            }
            $tr = $terms->findById($termId);
            if ($tr === null || $tr->taxonomyId !== $taxId) {
                throw new HttpNotFoundException($request);
            }
            $parentOpts = [];
            foreach ($terms->forTaxonomyOrdered($taxId) as $c) {
                if ($c->id !== $termId) {
                    $parentOpts[] = $c;
                }
            }

            return $twig->render($response, 'admin/content/taxonomies/terms/form.twig', $withCmsUser($request, array_merge($adminContext(), [
                'admin_nav' => 'content_types',
                'content_type' => $t,
                'taxonomy' => $tx,
                'term' => $tr,
                'parent_options' => $parentOpts,
                'errors' => [],
                'old' => null,
                'seo_media_select' => $mediaRepo->listImagesForPicker(200),
            ])));
        })->setName('admin.content_types.taxonomies.terms.edit');

        $group->post('/content-types/{id:[0-9]+}/taxonomies/{taxId:[0-9]+}/terms/{termId:[0-9]+}/edit', function (Request $request, Response $response, array $args) use ($twig, $adminContext, $withCmsUser, $types, $tax, $terms, $termValidator, $mediaRepo): Response {
            $id = (int) $args['id'];
            $taxId = (int) $args['taxId'];
            $termId = (int) $args['termId'];
            $t = $types->findById($id);
            if ($t === null) {
                throw new HttpNotFoundException($request);
            }
            $tx = $tax->findById($taxId);
            if ($tx === null || $tx->contentTypeId !== $id) {
                throw new HttpNotFoundException($request);
            }
            $tr = $terms->findById($termId);
            if ($tr === null || $tr->taxonomyId !== $taxId) {
                throw new HttpNotFoundException($request);
            }
            $body = $request->getParsedBody();
            $body = is_array($body) ? $body : [];
            $result = $termValidator->validate($body, $taxId, $termId, $tx, $terms);
            $seo = SeoFormParser::parseTerm($body, $mediaRepo);
            $allErr = array_merge($result['errors'], $seo['errors']);
            $parentOpts = [];
            foreach ($terms->forTaxonomyOrdered($taxId) as $c) {
                if ($c->id !== $termId) {
                    $parentOpts[] = $c;
                }
            }
            if ($allErr !== []) {
                return $twig->render($response, 'admin/content/taxonomies/terms/form.twig', $withCmsUser($request, array_merge($adminContext(), [
                    'admin_nav' => 'content_types',
                    'content_type' => $t,
                    'taxonomy' => $tx,
                    'term' => $tr,
                    'parent_options' => $parentOpts,
                    'errors' => $allErr,
                    'old' => array_merge($result['values'], [
                        'seo_title' => trim((string) ($body['seo_title'] ?? '')),
                        'seo_description' => trim((string) ($body['seo_description'] ?? '')),
                        'canonical_url' => trim((string) ($body['canonical_url'] ?? '')),
                        'seo_noindex' => !empty($body['seo_noindex']),
                        'og_title' => trim((string) ($body['og_title'] ?? '')),
                        'og_description' => trim((string) ($body['og_description'] ?? '')),
                        'og_image_id' => trim((string) ($body['og_image_id'] ?? '')),
                        'twitter_title' => trim((string) ($body['twitter_title'] ?? '')),
                        'twitter_description' => trim((string) ($body['twitter_description'] ?? '')),
                        'twitter_image_id' => trim((string) ($body['twitter_image_id'] ?? '')),
                        'schema_json' => (string) ($body['schema_json'] ?? ''),
                    ]),
                    'seo_media_select' => $mediaRepo->listImagesForPicker(200),
                ])));
            }
            $v = $result['values'];
            $slug = TaxonomyTermSlugger::ensureUnique($terms, $taxId, $v['slug'], $termId);
            $terms->update(
                $termId,
                $v['name'],
                $slug,
                $v['description'],
                $v['parent_id'],
                $v['sort_order'],
                $seo['seo_title'],
                $seo['seo_description'],
                $seo['focus_keyphrase'],
                $seo['canonical_url'],
                $seo['seo_noindex'],
                $seo['og_title'],
                $seo['og_description'],
                $seo['og_image_id'],
                $seo['twitter_title'],
                $seo['twitter_description'],
                $seo['twitter_image_id'],
                $seo['schema_json']
            );
            Flash::set('success', 'Term updated.');
            Events::dispatch(new StorefrontCachesInvalidateEvent('taxonomy_term_updated'));

            return $response
                ->withHeader('Location', RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.content_types.taxonomies.terms.index', ['id' => (string) $id, 'taxId' => (string) $taxId]))
                ->withStatus(302);
        })->setName('admin.content_types.taxonomies.terms.update');

        $group->post('/content-types/{id:[0-9]+}/taxonomies/{taxId:[0-9]+}/terms/{termId:[0-9]+}/delete', function (Request $request, Response $response, array $args) use ($terms): Response {
            $id = (int) $args['id'];
            $taxId = (int) $args['taxId'];
            $termId = (int) $args['termId'];
            if (!$terms->belongsToTaxonomy($termId, $taxId)) {
                throw new HttpNotFoundException($request);
            }
            $terms->delete($termId);
            Flash::set('success', 'Term deleted.');
            Events::dispatch(new StorefrontCachesInvalidateEvent('taxonomy_term_deleted'));

            return $response
                ->withHeader('Location', RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.content_types.taxonomies.terms.index', ['id' => (string) $id, 'taxId' => (string) $taxId]))
                ->withStatus(302);
        })->setName('admin.content_types.taxonomies.terms.delete');
    })->add($permTax)->add($middleware);
};
