<?php

declare(strict_types=1);

use App\Access\PermissionSlug;
use App\Event\Events;
use App\Event\StorefrontCachesInvalidateEvent;
use App\Flash;
use App\Http\Middleware\RequireCmsStaff;
use App\Http\Middleware\RequirePermission;
use App\Menu\MenuItemRepository;
use App\Menu\MenuItemValidator;
use App\Menu\MenuRepository;
use App\Menu\MenuValidator;
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
    $permMenus = new RequirePermission($pdo, [PermissionSlug::MANAGE_MENUS]);
    $menus = new MenuRepository($pdo);
    $items = new MenuItemRepository($pdo);
    $pages = new PageRepository($pdo);
    $menuValidator = new MenuValidator();
    $itemValidator = new MenuItemValidator();

    $adminContext = static function () use ($viewData): array {
        return array_merge($viewData(), []);
    };

    $withCmsUser = static function (Request $request, array $data): array {
        /** @var array<string, mixed> $cmsUser */
        $cmsUser = $request->getAttribute('cms_user') ?? [];

        return array_merge($data, ['cms_user' => $cmsUser]);
    };

    $pageOptions = static function () use ($pages): array {
        return $pages->idTitlePairsAll();
    };

    $app->group('/admin', function (\Slim\Routing\RouteCollectorProxy $group) use (
        $twig,
        $adminContext,
        $withCmsUser,
        $menus,
        $items,
        $menuValidator,
        $itemValidator,
        $pageOptions
    ): void {
        $group->get('/menus', function (Request $request, Response $response) use ($twig, $adminContext, $withCmsUser, $menus): Response {
            return $twig->render($response, 'admin/menus/list.twig', $withCmsUser($request, array_merge($adminContext(), [
                'admin_nav' => 'menus',
                'menus' => $menus->allOrdered(),
            ])));
        })->setName('admin.menus.index');

        $group->get('/menus/new', function (Request $request, Response $response) use ($twig, $adminContext, $withCmsUser): Response {
            return $twig->render($response, 'admin/menus/form.twig', $withCmsUser($request, array_merge($adminContext(), [
                'admin_nav' => 'menus',
                'form_mode' => 'create',
                'menu' => null,
                'errors' => [],
                'old' => ['name' => '', 'location' => 'header'],
            ])));
        })->setName('admin.menus.new');

        $group->post('/menus/new', function (Request $request, Response $response) use ($twig, $adminContext, $withCmsUser, $menus, $menuValidator): Response {
            $body = $request->getParsedBody();
            $body = is_array($body) ? $body : [];
            $result = $menuValidator->validate($body);

            if ($result['errors'] !== []) {
                return $twig->render($response, 'admin/menus/form.twig', $withCmsUser($request, array_merge($adminContext(), [
                    'admin_nav' => 'menus',
                    'form_mode' => 'create',
                    'menu' => null,
                    'errors' => $result['errors'],
                    'old' => $result['values'],
                ])));
            }

            $v = $result['values'];
            if ($menus->locationTaken($v['location'], null)) {
                return $twig->render($response, 'admin/menus/form.twig', $withCmsUser($request, array_merge($adminContext(), [
                    'admin_nav' => 'menus',
                    'form_mode' => 'create',
                    'menu' => null,
                    'errors' => ['location' => 'That placement already has a menu. Edit it or pick the other placement.'],
                    'old' => $v,
                ])));
            }

            $menus->insert($v['name'], $v['location']);
            Flash::set('success', 'Menu created.');
            Events::dispatch(new StorefrontCachesInvalidateEvent('menu_created'));

            return $response
                ->withHeader('Location', RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.menus.index'))
                ->withStatus(302);
        })->setName('admin.menus.store');

        $group->get('/menus/{id:[0-9]+}/edit', function (Request $request, Response $response, array $args) use ($twig, $adminContext, $withCmsUser, $menus, $items, $pageOptions): Response {
            $id = (int) $args['id'];
            $menu = $menus->findById($id);
            if ($menu === null) {
                throw new HttpNotFoundException($request);
            }

            return $twig->render($response, 'admin/menus/edit.twig', $withCmsUser($request, array_merge($adminContext(), [
                'admin_nav' => 'menus',
                'menu' => $menu,
                'menu_items' => $items->forMenuOrdered($id),
                'menu_errors' => [],
                'menu_old' => null,
            ])));
        })->setName('admin.menus.edit');

        $group->post('/menus/{id:[0-9]+}/edit', function (Request $request, Response $response, array $args) use ($twig, $adminContext, $withCmsUser, $menus, $items, $menuValidator, $pageOptions): Response {
            $id = (int) $args['id'];
            $menu = $menus->findById($id);
            if ($menu === null) {
                throw new HttpNotFoundException($request);
            }

            $body = $request->getParsedBody();
            $body = is_array($body) ? $body : [];
            $result = $menuValidator->validate($body);

            if ($result['errors'] !== []) {
                return $twig->render($response, 'admin/menus/edit.twig', $withCmsUser($request, array_merge($adminContext(), [
                    'admin_nav' => 'menus',
                    'menu' => $menu,
                    'menu_items' => $items->forMenuOrdered($id),
                    'menu_errors' => $result['errors'],
                    'menu_old' => $result['values'],
                ])));
            }

            $v = $result['values'];
            if ($menus->locationTaken($v['location'], $id)) {
                return $twig->render($response, 'admin/menus/edit.twig', $withCmsUser($request, array_merge($adminContext(), [
                    'admin_nav' => 'menus',
                    'menu' => $menu,
                    'menu_items' => $items->forMenuOrdered($id),
                    'menu_errors' => ['location' => 'That placement is already used by another menu.'],
                    'menu_old' => $v,
                ])));
            }

            $menus->update($id, $v['name'], $v['location']);
            Flash::set('success', 'Menu updated.');
            Events::dispatch(new StorefrontCachesInvalidateEvent('menu_updated'));

            return $response
                ->withHeader('Location', RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.menus.edit', ['id' => (string) $id]))
                ->withStatus(302);
        })->setName('admin.menus.update');

        $group->post('/menus/{id:[0-9]+}/delete', function (Request $request, Response $response, array $args) use ($menus): Response {
            $id = (int) $args['id'];
            $menu = $menus->findById($id);
            if ($menu === null) {
                throw new HttpNotFoundException($request);
            }
            $menus->delete($id);
            Flash::set('success', 'Menu deleted.');
            Events::dispatch(new StorefrontCachesInvalidateEvent('menu_deleted'));

            return $response
                ->withHeader('Location', RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.menus.index'))
                ->withStatus(302);
        })->setName('admin.menus.delete');

        $group->post('/menus/{id:[0-9]+}/items/reorder', function (Request $request, Response $response, array $args) use ($menus, $items): Response {
            $menuId = (int) $args['id'];
            $menu = $menus->findById($menuId);
            if ($menu === null) {
                throw new HttpNotFoundException($request);
            }

            $body = $request->getParsedBody();
            $order = is_array($body) && isset($body['order']) && is_array($body['order']) ? $body['order'] : [];
            foreach ($order as $itemIdRaw => $sortRaw) {
                $itemId = (int) $itemIdRaw;
                $sort = (int) $sortRaw;
                if ($itemId < 1) {
                    continue;
                }
                if (!$items->belongsToMenu($itemId, $menuId)) {
                    continue;
                }
                $items->updateSortOrder($menuId, $itemId, $sort);
            }

            Flash::set('success', 'Menu order updated.');
            Events::dispatch(new StorefrontCachesInvalidateEvent('menu_items_reordered'));

            return $response
                ->withHeader('Location', RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.menus.edit', ['id' => (string) $menuId]))
                ->withStatus(302);
        })->setName('admin.menus.items.reorder');

        $group->get('/menus/{id:[0-9]+}/items/new', function (Request $request, Response $response, array $args) use ($twig, $adminContext, $withCmsUser, $menus, $items, $pageOptions): Response {
            $menuId = (int) $args['id'];
            $menu = $menus->findById($menuId);
            if ($menu === null) {
                throw new HttpNotFoundException($request);
            }

            return $twig->render($response, 'admin/menus/item_form.twig', $withCmsUser($request, array_merge($adminContext(), [
                'admin_nav' => 'menus',
                'menu' => $menu,
                'item' => null,
                'page_options' => $pageOptions(),
                'errors' => [],
                'old' => [
                    'label' => '',
                    'url' => '',
                    'page_id' => '',
                    'sort_order' => (string) $items->nextSortOrder($menuId),
                    'target' => '_self',
                    'css_class' => '',
                ],
            ])));
        })->setName('admin.menus.items.new');

        $group->post('/menus/{id:[0-9]+}/items/new', function (Request $request, Response $response, array $args) use ($twig, $adminContext, $withCmsUser, $menus, $items, $itemValidator, $pageOptions): Response {
            $menuId = (int) $args['id'];
            $menu = $menus->findById($menuId);
            if ($menu === null) {
                throw new HttpNotFoundException($request);
            }

            $body = $request->getParsedBody();
            $body = is_array($body) ? $body : [];
            $result = $itemValidator->validate($body);

            if ($result['errors'] !== []) {
                $v = $result['values'];

                return $twig->render($response, 'admin/menus/item_form.twig', $withCmsUser($request, array_merge($adminContext(), [
                    'admin_nav' => 'menus',
                    'menu' => $menu,
                    'item' => null,
                    'page_options' => $pageOptions(),
                    'errors' => $result['errors'],
                    'old' => [
                        'label' => $v['label'],
                        'url' => $v['url'],
                        'page_id' => $v['page_id'] !== null ? (string) $v['page_id'] : '',
                        'sort_order' => (string) $v['sort_order'],
                        'target' => $v['target'],
                        'css_class' => $v['css_class'],
                    ],
                ])));
            }

            $v = $result['values'];
            $items->insert(
                $menuId,
                $v['label'],
                $v['url'],
                $v['page_id'],
                $v['sort_order'],
                $v['target'],
                $v['css_class']
            );
            Flash::set('success', 'Menu item added.');
            Events::dispatch(new StorefrontCachesInvalidateEvent('menu_item_added'));

            return $response
                ->withHeader('Location', RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.menus.edit', ['id' => (string) $menuId]))
                ->withStatus(302);
        })->setName('admin.menus.items.store');

        $group->get('/menus/{id:[0-9]+}/items/{itemId:[0-9]+}/edit', function (Request $request, Response $response, array $args) use ($twig, $adminContext, $withCmsUser, $menus, $items, $pageOptions): Response {
            $menuId = (int) $args['id'];
            $itemId = (int) $args['itemId'];
            $menu = $menus->findById($menuId);
            if ($menu === null) {
                throw new HttpNotFoundException($request);
            }
            $item = $items->findById($itemId);
            if ($item === null || $item->menuId !== $menuId) {
                throw new HttpNotFoundException($request);
            }

            return $twig->render($response, 'admin/menus/item_form.twig', $withCmsUser($request, array_merge($adminContext(), [
                'admin_nav' => 'menus',
                'menu' => $menu,
                'item' => $item,
                'page_options' => $pageOptions(),
                'errors' => [],
                'old' => [],
            ])));
        })->setName('admin.menus.items.edit');

        $group->post('/menus/{id:[0-9]+}/items/{itemId:[0-9]+}/edit', function (Request $request, Response $response, array $args) use ($twig, $adminContext, $withCmsUser, $menus, $items, $itemValidator, $pageOptions): Response {
            $menuId = (int) $args['id'];
            $itemId = (int) $args['itemId'];
            $menu = $menus->findById($menuId);
            if ($menu === null) {
                throw new HttpNotFoundException($request);
            }
            $item = $items->findById($itemId);
            if ($item === null || $item->menuId !== $menuId) {
                throw new HttpNotFoundException($request);
            }

            $body = $request->getParsedBody();
            $body = is_array($body) ? $body : [];
            $result = $itemValidator->validate($body);

            if ($result['errors'] !== []) {
                $v = $result['values'];

                return $twig->render($response, 'admin/menus/item_form.twig', $withCmsUser($request, array_merge($adminContext(), [
                    'admin_nav' => 'menus',
                    'menu' => $menu,
                    'item' => $item,
                    'page_options' => $pageOptions(),
                    'errors' => $result['errors'],
                    'old' => [
                        'label' => $v['label'],
                        'url' => $v['url'],
                        'page_id' => $v['page_id'] !== null ? (string) $v['page_id'] : '',
                        'sort_order' => (string) $v['sort_order'],
                        'target' => $v['target'],
                        'css_class' => $v['css_class'],
                    ],
                ])));
            }

            $v = $result['values'];
            $items->update($itemId, $v['label'], $v['url'], $v['page_id'], $v['sort_order'], $v['target'], $v['css_class']);
            Flash::set('success', 'Menu item updated.');
            Events::dispatch(new StorefrontCachesInvalidateEvent('menu_item_updated'));

            return $response
                ->withHeader('Location', RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.menus.edit', ['id' => (string) $menuId]))
                ->withStatus(302);
        })->setName('admin.menus.items.update');

        $group->post('/menus/{id:[0-9]+}/items/{itemId:[0-9]+}/delete', function (Request $request, Response $response, array $args) use ($menus, $items): Response {
            $menuId = (int) $args['id'];
            $itemId = (int) $args['itemId'];
            $menu = $menus->findById($menuId);
            if ($menu === null) {
                throw new HttpNotFoundException($request);
            }
            $item = $items->findById($itemId);
            if ($item === null || $item->menuId !== $menuId) {
                throw new HttpNotFoundException($request);
            }
            $items->delete($itemId);
            Flash::set('success', 'Menu item removed.');
            Events::dispatch(new StorefrontCachesInvalidateEvent('menu_item_deleted'));

            return $response
                ->withHeader('Location', RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.menus.edit', ['id' => (string) $menuId]))
                ->withStatus(302);
        })->setName('admin.menus.items.delete');
    })->add($permMenus)->add($middleware);
};
