<?php

declare(strict_types=1);

use App\Content\ContentEntryValueRepository;
use App\Content\ContentFieldRepository;
use App\Content\List\ContentListQueryRunner;
use App\Content\List\ContentListRepository;
use App\Content\List\ContentListService;
use App\Content\ContentTypeRepository;
use App\Content\ContentViewTemplates;
use App\Content\PublicContentIndexCardBuilder;
use App\Media\MediaUrlHelper;
use App\Seo\MetaTagBuilder;
use App\Seo\SeoService;
use App\Settings;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Slim\Exception\HttpNotFoundException;
use Slim\Views\Twig;

return static function (App $app, Twig $twig, \PDO $pdo, callable $viewData): void {
    $fields = new ContentFieldRepository($pdo);
    $service = new ContentListService(
        $pdo,
        new ContentListRepository($pdo),
        new ContentTypeRepository($pdo),
        new ContentListQueryRunner($pdo, $fields),
        new PublicContentIndexCardBuilder($fields, new ContentEntryValueRepository($pdo), new MediaUrlHelper($pdo)),
    );
    $mediaUrls = new MediaUrlHelper($pdo);

    $app->get('/lists/{listSlug:[a-z0-9]+(?:-[a-z0-9]+)*}', function (
        Request $request,
        Response $response,
        array $args,
    ) use ($twig, $viewData, $service, $mediaUrls): Response {
        $slug = (string) ($args['listSlug'] ?? '');
        $query = $request->getQueryParams();
        $page = isset($query['page']) && is_numeric($query['page']) ? max(1, (int) $query['page']) : 1;

        $pack = $service->runForPublicPage($slug, $page);
        if ($pack === null) {
            throw new HttpNotFoundException($request);
        }

        $list = $pack['list'];
        $type = $pack['type'];
        $tplCandidates = [
            'content_lists/' . $slug . '.twig',
            'content_lists/show.twig',
            'content/' . $type->slug . '/index.twig',
            'content/index.twig',
        ];
        $tpl = ContentViewTemplates::resolve($twig->getEnvironment(), $tplCandidates);

        $siteUrl = rtrim((string) (($viewData())['site_url'] ?? ''), '/');
        $title = (string) ($list['name'] ?? 'List');
        $description = trim((string) ($list['description'] ?? ''));
        $seoSvc = new SeoService($mediaUrls);
        $seoTwig = MetaTagBuilder::twigVars($seoSvc->resolveForContentTypeIndex(
            $type,
            '/lists/' . $slug,
            $siteUrl,
            Settings::get('site_name') ?: null,
        ));

        return $twig->render($response, $tpl, array_merge($viewData(), $seoTwig, [
            'content_list' => $list,
            'content_list_slug' => $slug,
            'content_type' => $type,
            'index_entries' => $pack['cards'],
            'index_page' => $pack['page'],
            'index_per_page' => $pack['per_page'],
            'index_total' => $pack['total'],
            'index_total_pages' => $pack['total_pages'],
            'index_pager_items' => $pack['page_items'],
            'content_index_title' => $title,
            'content_index_description' => $description,
        ]));
    })->setName('public.content_list');
};
