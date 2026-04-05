<?php

declare(strict_types=1);

use App\Content\ContentTypeRepository;
use App\Content\ContentViewTemplates;
use App\Content\ReservedContentSlugs;
use App\Media\MediaUrlHelper;
use App\Seo\MetaTagBuilder;
use App\Seo\SeoService;
use App\Settings;
use App\Taxonomy\TaxonomyArchiveQuery;
use App\Taxonomy\TaxonomyRepository;
use App\Taxonomy\TaxonomyTermRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Slim\Exception\HttpNotFoundException;
use Slim\Views\Twig;

return static function (App $app, Twig $twig, \PDO $pdo, callable $viewData): void {
    $types = new ContentTypeRepository($pdo);
    $taxRepo = new TaxonomyRepository($pdo);
    $termRepo = new TaxonomyTermRepository($pdo);
    $archive = new TaxonomyArchiveQuery($pdo);
    $mediaUrls = new MediaUrlHelper($pdo);

    $app->get('/{typeSlug}/{taxonomySlug}/{termSlug}', function (Request $request, Response $response, array $args) use (
        $twig,
        $viewData,
        $types,
        $taxRepo,
        $termRepo,
        $archive,
        $mediaUrls
    ): Response {
        $typeSlug = (string) ($args['typeSlug'] ?? '');
        $taxonomySlug = (string) ($args['taxonomySlug'] ?? '');
        $termSlug = (string) ($args['termSlug'] ?? '');
        if (ReservedContentSlugs::isReserved($typeSlug)) {
            throw new HttpNotFoundException($request);
        }

        $type = $types->findBySlug($typeSlug);
        if ($type === null || !$type->hasPublicRoute) {
            throw new HttpNotFoundException($request);
        }

        $taxonomy = $taxRepo->findByContentTypeAndSlug($type->id, $taxonomySlug);
        if ($taxonomy === null) {
            throw new HttpNotFoundException($request);
        }

        $term = $termRepo->findByTaxonomyAndSlug($taxonomy->id, $termSlug);
        if ($term === null) {
            throw new HttpNotFoundException($request);
        }

        $query = $request->getQueryParams();
        $page = isset($query['page']) && is_numeric($query['page']) ? max(1, (int) $query['page']) : 1;
        $perPage = 12;
        $total = $archive->countPublishedForTerm($term->id);
        $totalPages = max(1, (int) ceil($total / $perPage));
        if ($page > $totalPages) {
            $page = $totalPages;
        }
        $rows = $archive->publishedEntriesForTermPaged($term->id, $page, $perPage);

        $pageTitle = $term->name . ' — ' . $taxonomy->name . ' — ' . $type->name;
        $metaDesc = $term->description ?? $taxonomy->description ?? '';

        $tpl = ContentViewTemplates::resolve(
            $twig->getEnvironment(),
            ContentViewTemplates::taxonomyArchive($type->slug, $taxonomy->slug)
        );

        $siteUrl = rtrim((string) (($viewData())['site_url'] ?? ''), '/');
        $path = '/' . $typeSlug . '/' . $taxonomySlug . '/' . $termSlug;
        $seoSvc = new SeoService($mediaUrls);
        $seoTwig = MetaTagBuilder::twigVars($seoSvc->resolveForTaxonomyTerm(
            $term,
            $taxonomy,
            $type,
            $path,
            $siteUrl,
            Settings::get('site_name') ?: null
        ));

        return $twig->render($response, $tpl, array_merge($viewData(), $seoTwig, [
            'content_type' => $type,
            'taxonomy' => $taxonomy,
            'taxonomy_term' => $term,
            'archive_entries' => $rows,
            'archive_page' => $page,
            'archive_per_page' => $perPage,
            'archive_total' => $total,
            'archive_total_pages' => $totalPages,
            'taxonomy_page_title' => $pageTitle,
            'taxonomy_meta_description' => $metaDesc,
        ]));
    })->setName('public.taxonomy_archive');
};
