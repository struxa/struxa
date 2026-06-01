<?php

declare(strict_types=1);

use App\Access\PermissionSlug;
use App\Content\ContentFieldRepository;
use App\Content\ContentTypeRepository;
use App\Content\List\ContentListRepository;
use App\Content\List\ContentListService;
use App\Content\List\ContentListValidator;
use App\Content\List\ContentListQueryRunner;
use App\Content\PublicContentIndexCardBuilder;
use App\Content\ContentEntryValueRepository;
use App\Flash;
use App\Http\Middleware\RequireCmsStaff;
use App\Http\Middleware\RequirePermission;
use App\Media\MediaUrlHelper;
use App\Event\Events;
use App\Event\StorefrontCachesInvalidateEvent;
use App\Taxonomy\TaxonomyRepository;
use App\Taxonomy\TaxonomyTermRepository;
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
    $perm = new RequirePermission($pdo, [PermissionSlug::MANAGE_CONTENT_TYPES]);

    $lists = new ContentListRepository($pdo);
    $types = new ContentTypeRepository($pdo);
    $fields = new ContentFieldRepository($pdo);
    $terms = new TaxonomyTermRepository($pdo);
    $taxonomies = new TaxonomyRepository($pdo);
    $validator = new ContentListValidator($types, $fields, $terms, $lists);

    $listService = new ContentListService(
        $pdo,
        $lists,
        $types,
        new ContentListQueryRunner($pdo, $fields),
        new PublicContentIndexCardBuilder($fields, new ContentEntryValueRepository($pdo), new MediaUrlHelper($pdo)),
    );

    $adminContext = static fn (): array => $viewData(['admin_nav' => 'content_lists']);
    $withCmsUser = static function (Request $request, array $data): array {
        /** @var array<string, mixed> $cmsUser */
        $cmsUser = $request->getAttribute('cms_user') ?? [];

        return array_merge($data, ['cms_user' => $cmsUser]);
    };

    $termOptionsForType = static function (int $typeId) use ($taxonomies, $terms): array {
        if ($typeId < 1) {
            return [];
        }
        $out = [];
        foreach ($taxonomies->forContentTypeOrdered($typeId) as $tax) {
            foreach ($terms->forTaxonomyOrdered($tax->id) as $term) {
                $out[] = [
                    'id' => $term->id,
                    'label' => $tax->name . ' › ' . $term->name,
                ];
            }
        }

        return $out;
    };

    $formContext = static function (?array $listRow) use ($types, $fields, $termOptionsForType): array {
        $typeId = $listRow !== null ? (int) ($listRow['content_type_id'] ?? 0) : 0;
        $def = $listRow !== null ? ContentListRepository::definitionFromRow($listRow) : null;
        $typeFields = $typeId > 0 ? $fields->forTypeOrdered($typeId) : [];

        return [
            'content_types' => $types->allOrdered(),
            'type_fields' => $typeFields,
            'term_options' => $termOptionsForType($typeId),
            'list_def' => $def,
            'list_row' => $listRow,
        ];
    };

    $app->group('/admin/content-lists', function (\Slim\Routing\RouteCollectorProxy $group) use (
        $twig,
        $lists,
        $validator,
        $listService,
        $adminContext,
        $withCmsUser,
        $formContext
    ): void {
        $group->get('', function (Request $request, Response $response) use ($twig, $lists, $adminContext, $withCmsUser): Response {
            return $twig->render($response, 'admin/content_lists/index.twig', $withCmsUser($request, array_merge($adminContext(), [
                'list_rows' => $lists->listForAdmin(),
            ])));
        })->setName('admin.content_lists.index');

        $group->get('/new', function (Request $request, Response $response) use ($twig, $adminContext, $withCmsUser, $formContext): Response {
            return $twig->render($response, 'admin/content_lists/form.twig', $withCmsUser($request, array_merge($adminContext(), $formContext(null), [
                'form_mode' => 'new',
            ])));
        })->setName('admin.content_lists.new');

        $group->post('/new', function (Request $request, Response $response) use ($validator, $lists): Response {
            $body = $request->getParsedBody();
            $body = is_array($body) ? $body : [];
            $result = $validator->validateSave($body);
            if ($result['ok'] !== true) {
                Flash::set('error', $result['error']);

                return $response->withHeader('Location', RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.content_lists.new'))->withStatus(302);
            }
            $id = $lists->create($result['clean']);
            Events::dispatch(new StorefrontCachesInvalidateEvent('content_list_created'));
            Flash::set('success', 'Content list created.');

            return $response->withHeader('Location', RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.content_lists.edit', ['id' => (string) $id]))->withStatus(302);
        })->setName('admin.content_lists.create');

        $group->get('/{id:[0-9]+}/edit', function (Request $request, Response $response, array $args) use ($twig, $lists, $adminContext, $withCmsUser, $formContext): Response {
            $id = (int) ($args['id'] ?? 0);
            $row = $lists->findById($id);
            if ($row === null) {
                Flash::set('error', 'List not found.');

                return $response->withHeader('Location', RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.content_lists.index'))->withStatus(302);
            }

            return $twig->render($response, 'admin/content_lists/form.twig', $withCmsUser($request, array_merge($adminContext(), $formContext($row), [
                'form_mode' => 'edit',
            ])));
        })->setName('admin.content_lists.edit');

        $group->post('/{id:[0-9]+}/edit', function (Request $request, Response $response, array $args) use ($validator, $lists): Response {
            $id = (int) ($args['id'] ?? 0);
            $row = $lists->findById($id);
            if ($row === null) {
                Flash::set('error', 'List not found.');

                return $response->withHeader('Location', RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.content_lists.index'))->withStatus(302);
            }
            $body = $request->getParsedBody();
            $body = is_array($body) ? $body : [];
            $result = $validator->validateSave($body, $id);
            if ($result['ok'] !== true) {
                Flash::set('error', $result['error']);

                return $response->withHeader('Location', RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.content_lists.edit', ['id' => (string) $id]))->withStatus(302);
            }
            $lists->update($id, $result['clean']);
            Events::dispatch(new StorefrontCachesInvalidateEvent('content_list_updated'));
            Flash::set('success', 'Content list saved.');

            return $response->withHeader('Location', RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.content_lists.edit', ['id' => (string) $id]))->withStatus(302);
        })->setName('admin.content_lists.save');

        $group->post('/{id:[0-9]+}/delete', function (Request $request, Response $response, array $args) use ($lists): Response {
            $id = (int) ($args['id'] ?? 0);
            $lists->delete($id);
            Events::dispatch(new StorefrontCachesInvalidateEvent('content_list_deleted'));
            Flash::set('success', 'Content list deleted.');

            return $response->withHeader('Location', RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.content_lists.index'))->withStatus(302);
        })->setName('admin.content_lists.delete');

        $group->get('/{id:[0-9]+}/preview', function (Request $request, Response $response, array $args) use ($twig, $lists, $listService, $adminContext, $withCmsUser): Response {
            $id = (int) ($args['id'] ?? 0);
            $row = $lists->findById($id);
            if ($row === null) {
                Flash::set('error', 'List not found.');

                return $response->withHeader('Location', RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.content_lists.index'))->withStatus(302);
            }
            $page = isset($request->getQueryParams()['page']) && is_numeric($request->getQueryParams()['page'])
                ? max(1, (int) $request->getQueryParams()['page'])
                : 1;
            $pack = $listService->runForAdminPreview($id, $page);
            if ($pack === null) {
                Flash::set('error', 'Could not run this list.');

                return $response->withHeader('Location', RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.content_lists.edit', ['id' => (string) $id]))->withStatus(302);
            }

            return $twig->render($response, 'admin/content_lists/preview.twig', $withCmsUser($request, array_merge($adminContext(), [
                'list_row' => $row,
                'preview' => $pack,
            ])));
        })->setName('admin.content_lists.preview');
    })->add($perm)->add($middleware);
};
