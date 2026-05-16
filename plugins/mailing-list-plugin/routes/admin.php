<?php

declare(strict_types=1);

use App\Flash;
use App\Http\Middleware\RequireCmsStaff;
use App\Plugin\PluginBootContext;
use MailingListPlugin\ListRepository;
use MailingListPlugin\ListValidator;
use MailingListPlugin\Slugger;
use MailingListPlugin\SubscriberRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Slim\Exception\HttpNotFoundException;
use Slim\Routing\RouteContext;

return function (App $app, PluginBootContext $ctx): void {
    $middleware = new RequireCmsStaff($ctx->auth(), $ctx->pdo());
    $twig = $ctx->twig();
    $pdo = $ctx->pdo();
    $lists = new ListRepository($pdo);
    $subscribers = new SubscriberRepository($pdo);

    $adminView = static function (array $extra = []) use ($ctx): array {
        return array_merge($ctx->viewData(), ['admin_nav' => 'extensions_plugins'], $extra);
    };

    $app->group('/admin', function (\Slim\Routing\RouteCollectorProxy $group) use ($ctx, $twig, $lists, $subscribers, $adminView): void {
        $group->get('/mailing-list-plugin', function (Request $request, Response $response): Response {
            $parser = RouteContext::fromRequest($request)->getRouteParser();

            return $response->withHeader('Location', $parser->urlFor('plugin.mailing_list_plugin.lists.index'))->withStatus(302);
        })->setName('plugin.mailing_list_plugin.home');

        $group->get('/mailing-list-plugin/lists', function (Request $request, Response $response) use ($twig, $lists, $adminView): Response {
            $rows = [];
            foreach ($lists->allOrdered() as $list) {
                $rows[] = [
                    'list' => $list,
                    'subscriber_count' => $lists->countSubscribers($list->id),
                ];
            }

            return $twig->render($response, '@plugin_mailing_list_plugin/admin/lists/index.twig', $adminView([
                'list_rows' => $rows,
            ]));
        })->setName('plugin.mailing_list_plugin.lists.index');

        $group->get('/mailing-list-plugin/lists/new', function (Request $request, Response $response) use ($twig, $adminView): Response {
            return $twig->render($response, '@plugin_mailing_list_plugin/admin/lists/form.twig', $adminView([
                'list' => null,
                'errors' => [],
                'old' => null,
            ]));
        })->setName('plugin.mailing_list_plugin.lists.new');

        $group->post('/mailing-list-plugin/lists/new', function (Request $request, Response $response) use ($twig, $lists, $adminView): Response {
            $body = $request->getParsedBody();
            $body = is_array($body) ? $body : [];
            $result = ListValidator::validate($body, $lists, null);
            if ($result['errors'] !== []) {
                return $twig->render($response, '@plugin_mailing_list_plugin/admin/lists/form.twig', $adminView([
                    'list' => null,
                    'errors' => $result['errors'],
                    'old' => $result['values'],
                ]));
            }
            $v = $result['values'];
            $slug = Slugger::ensureUnique($lists, $v['slug']);
            $lists->insert($slug, $v['name'], $v['description'] !== '' ? $v['description'] : null, $v['is_active']);
            Flash::set('success', 'Mailing list created.');

            return $response
                ->withHeader('Location', RouteContext::fromRequest($request)->getRouteParser()->urlFor('plugin.mailing_list_plugin.lists.index'))
                ->withStatus(302);
        })->setName('plugin.mailing_list_plugin.lists.store');

        $group->get('/mailing-list-plugin/lists/{id:[0-9]+}/edit', function (Request $request, Response $response, array $args) use ($twig, $lists, $adminView): Response {
            $list = $lists->findById((int) $args['id']);
            if ($list === null) {
                throw new HttpNotFoundException($request);
            }

            return $twig->render($response, '@plugin_mailing_list_plugin/admin/lists/form.twig', $adminView([
                'list' => $list,
                'errors' => [],
                'old' => null,
            ]));
        })->setName('plugin.mailing_list_plugin.lists.edit');

        $group->post('/mailing-list-plugin/lists/{id:[0-9]+}/edit', function (Request $request, Response $response, array $args) use ($twig, $lists, $adminView): Response {
            $id = (int) $args['id'];
            $list = $lists->findById($id);
            if ($list === null) {
                throw new HttpNotFoundException($request);
            }
            $body = $request->getParsedBody();
            $body = is_array($body) ? $body : [];
            $result = ListValidator::validate($body, $lists, $id);
            if ($result['errors'] !== []) {
                return $twig->render($response, '@plugin_mailing_list_plugin/admin/lists/form.twig', $adminView([
                    'list' => $list,
                    'errors' => $result['errors'],
                    'old' => $result['values'],
                ]));
            }
            $v = $result['values'];
            $slug = Slugger::ensureUnique($lists, $v['slug'], $id);
            $lists->update($id, $slug, $v['name'], $v['description'] !== '' ? $v['description'] : null, $v['is_active']);
            Flash::set('success', 'Mailing list saved.');

            return $response
                ->withHeader('Location', RouteContext::fromRequest($request)->getRouteParser()->urlFor('plugin.mailing_list_plugin.lists.index'))
                ->withStatus(302);
        })->setName('plugin.mailing_list_plugin.lists.update');

        $group->post('/mailing-list-plugin/lists/{id:[0-9]+}/delete', function (Request $request, Response $response, array $args) use ($lists): Response {
            $id = (int) $args['id'];
            if ($lists->findById($id) !== null) {
                $lists->delete($id);
                Flash::set('success', 'Mailing list deleted.');
            }

            return $response
                ->withHeader('Location', RouteContext::fromRequest($request)->getRouteParser()->urlFor('plugin.mailing_list_plugin.lists.index'))
                ->withStatus(302);
        })->setName('plugin.mailing_list_plugin.lists.delete');

        $group->get('/mailing-list-plugin/lists/{id:[0-9]+}/subscribers', function (Request $request, Response $response, array $args) use ($twig, $lists, $subscribers, $adminView): Response {
            $id = (int) $args['id'];
            $list = $lists->findById($id);
            if ($list === null) {
                throw new HttpNotFoundException($request);
            }
            $query = $request->getQueryParams();
            $page = isset($query['page']) && is_numeric($query['page']) ? max(1, (int) $query['page']) : 1;
            $paged = $subscribers->forListPaged($id, $page, 50);

            return $twig->render($response, '@plugin_mailing_list_plugin/admin/subscribers/index.twig', $adminView([
                'mailing_list' => $list,
                'subscriber_rows' => $paged['rows'],
                'subscriber_total' => $paged['total'],
                'page' => $page,
                'per_page' => 50,
            ]));
        })->setName('plugin.mailing_list_plugin.subscribers.index');

        $group->post('/mailing-list-plugin/lists/{listId:[0-9]+}/subscribers/{subscriberId:[0-9]+}/delete', function (Request $request, Response $response, array $args) use ($subscribers): Response {
            $listId = (int) $args['listId'];
            $subscriberId = (int) $args['subscriberId'];
            if ($subscribers->delete($subscriberId, $listId)) {
                Flash::set('success', 'Subscriber removed.');
            }

            return $response
                ->withHeader('Location', RouteContext::fromRequest($request)->getRouteParser()->urlFor('plugin.mailing_list_plugin.subscribers.index', ['id' => (string) $listId]))
                ->withStatus(302);
        })->setName('plugin.mailing_list_plugin.subscribers.delete');
    })->add($middleware);
};
