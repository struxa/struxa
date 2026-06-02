<?php

declare(strict_types=1);

use App\Access\PermissionSlug;
use App\Content\ContentTypeRepository;
use App\Event\Events;
use App\Event\StorefrontCachesInvalidateEvent;
use App\Flash;
use App\Http\Middleware\RequireCmsStaff;
use App\Http\Middleware\RequirePermission;
use App\Search\SearchSettings;
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

    $adminContext = static fn (): array => array_merge($viewData(), []);
    $withCmsUser = static function (Request $request, array $data): array {
        /** @var array<string, mixed> $cmsUser */
        $cmsUser = $request->getAttribute('cms_user') ?? [];

        return array_merge($data, ['cms_user' => $cmsUser]);
    };

    $app->get('/admin/settings/search', function (
        Request $request,
        Response $response
    ) use ($twig, $pdo, $adminContext, $withCmsUser): Response {
        $types = (new ContentTypeRepository($pdo))->allWithPublicRoute();
        $allowed = array_flip(SearchSettings::allowedTypeIds());

        $typeRows = [];
        foreach ($types as $t) {
            $typeRows[] = [
                'id' => $t->id,
                'name' => $t->name,
                'slug' => $t->slug,
                'enabled' => isset($allowed[$t->id]),
            ];
        }

        return $twig->render($response, 'admin/settings/search.twig', $withCmsUser($request, array_merge($adminContext(), [
            'admin_nav' => 'settings_search',
            'search_enabled' => SearchSettings::enabled(),
            'search_include_fields' => SearchSettings::includeFieldValues(),
            'search_per_page' => SearchSettings::perPage(),
            'search_type_rows' => $typeRows,
            'search_min_per_page' => SearchSettings::PER_PAGE_MIN,
            'search_max_per_page' => SearchSettings::PER_PAGE_MAX,
        ])));
    })->setName('admin.settings.search')->add($perm)->add($middleware);

    $app->post('/admin/settings/search', function (
        Request $request,
        Response $response
    ) use ($pdo): Response {
        $body = (array) $request->getParsedBody();
        $enabled = !empty($body['enabled']);
        $includeFields = !empty($body['include_fields']);
        $perPage = isset($body['per_page']) && is_string($body['per_page']) ? (int) $body['per_page'] : SearchSettings::PER_PAGE_DEFAULT;

        $rawIds = isset($body['type_ids']) && is_array($body['type_ids']) ? $body['type_ids'] : [];
        $publicTypes = (new ContentTypeRepository($pdo))->allWithPublicRoute();
        $publicIds = [];
        foreach ($publicTypes as $t) {
            $publicIds[$t->id] = true;
        }
        $cleanIds = [];
        foreach ($rawIds as $id) {
            $id = (int) $id;
            if ($id > 0 && isset($publicIds[$id])) {
                $cleanIds[$id] = true;
            }
        }
        SearchSettings::save($pdo, $enabled, array_keys($cleanIds), $includeFields, $perPage);
        Events::dispatch(new StorefrontCachesInvalidateEvent('search_settings'));
        Flash::set('success', 'Search settings saved.');

        $url = RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.settings.search');

        return $response->withHeader('Location', $url)->withStatus(302);
    })->setName('admin.settings.search.save')->add($perm)->add($middleware);
};
