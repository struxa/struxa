<?php

declare(strict_types=1);

use App\Commerce\CommerceSettings;
use App\Commerce\Product\ProductCatalogEnricher;
use App\Commerce\Product\ProductResolver;
use App\Content\ContentEntryRepository;
use App\Content\ContentEntryValueRepository;
use App\Content\ContentFieldRepository;
use App\Content\ContentTypeRepository;
use App\Content\ContentViewTemplates;
use App\Content\PublicContentIndexCardBuilder;
use App\Content\PublicContentIndexPager;
use App\Content\ReservedContentSlugs;
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
    $types = new ContentTypeRepository($pdo);
    $fields = new ContentFieldRepository($pdo);
    $entries = new ContentEntryRepository($pdo);
    $values = new ContentEntryValueRepository($pdo);
    $mediaUrls = new MediaUrlHelper($pdo);
    $indexCardBuilder = new PublicContentIndexCardBuilder($fields, $values, $mediaUrls);
    $commerce = new CommerceSettings($pdo);
    $products = new ProductResolver($pdo, $commerce, $fields);
    $catalogEnricher = new ProductCatalogEnricher($products, $entries, $values);

    $app->get('/{typeSlug}', function (Request $request, Response $response, array $args) use (
        $twig,
        $viewData,
        $pdo,
        $types,
        $entries,
        $mediaUrls,
        $indexCardBuilder,
        $catalogEnricher
    ): Response {
        $typeSlug = (string) ($args['typeSlug'] ?? '');
        if (ReservedContentSlugs::isReserved($typeSlug)) {
            throw new HttpNotFoundException($request);
        }

        $type = $types->findBySlug($typeSlug);
        if ($type === null || !$type->hasPublicRoute) {
            throw new HttpNotFoundException($request);
        }

        $query = $request->getQueryParams();
        $page = isset($query['page']) && is_numeric($query['page']) ? max(1, (int) $query['page']) : 1;
        $perPage = 6;
        $total = $entries->countPublishedForContentType($type->id);
        $totalPages = max(1, (int) ceil(max(1, $total) / $perPage));
        if ($page > $totalPages) {
            $page = $totalPages;
        }
        $rows = $entries->publishedForContentTypePaged($type->id, $page, $perPage);
        $indexRows = $catalogEnricher->enrich($type, $indexCardBuilder->buildForEntries($type, $rows));

        $tpl = ContentViewTemplates::resolve($twig->getEnvironment(), ContentViewTemplates::contentIndex($type->slug));

        $siteUrl = rtrim((string) (($viewData())['site_url'] ?? ''), '/');
        $seoSvc = new SeoService($mediaUrls);
        $seoTwig = MetaTagBuilder::twigVars($seoSvc->resolveForContentTypeIndex(
            $type,
            '/' . $typeSlug,
            $siteUrl,
            Settings::get('site_name') ?: null
        ));

        $renderVars = array_merge($viewData(), $seoTwig, [
            'content_type' => $type,
            'index_entries' => $indexRows,
            'index_page' => $page,
            'index_per_page' => $perPage,
            'index_total' => $total,
            'index_total_pages' => $totalPages,
            'index_pager_items' => PublicContentIndexPager::pageItems($page, $totalPages),
            'content_index_title' => $type->name,
            'content_index_description' => $type->description ?? '',
        ]);

        if ($typeSlug === 'kb' && class_exists(\KnowledgeBasePlugin\KnowledgeBasePublicBridge::class)) {
            $renderVars = array_merge($renderVars, \KnowledgeBasePlugin\KnowledgeBasePublicBridge::indexViewData($pdo));
        }

        return $twig->render($response, $tpl, $renderVars);
    })->setName('public.content_type_index');
};
