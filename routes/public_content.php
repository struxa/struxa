<?php

declare(strict_types=1);

use App\Content\ContentEntryRepository;
use App\Content\ContentEntrySeoHelper;
use App\Content\ContentEntryValueRepository;
use App\Content\ContentEntryViewPresenter;
use App\Content\ContentFieldRepository;
use App\Content\ContentTypeRepository;
use App\Content\ContentViewTemplates;
use App\Comment\CommentRepository;
use App\Comment\CommentThreadBuilder;
use App\Content\ReservedContentSlugs;
use App\Media\MediaUrlHelper;
use App\Seo\MetaTagBuilder;
use App\Seo\SeoService;
use App\Settings;
use App\Taxonomy\ContentEntryTaxonomyRepository;
use App\Section\ContentEntrySectionRepository;
use App\Section\SectionManager;
use App\Section\SectionRenderer;
use App\Section\SectionTemplateResolver;
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
    $entryTaxonomies = new ContentEntryTaxonomyRepository($pdo);

    $app->get('/{typeSlug}/{entrySlug}', function (Request $request, Response $response, array $args) use (
        $twig,
        $viewData,
        $pdo,
        $types,
        $fields,
        $entries,
        $values,
        $mediaUrls,
        $entryTaxonomies
    ): Response {
        $typeSlug = (string) ($args['typeSlug'] ?? '');
        $entrySlug = (string) ($args['entrySlug'] ?? '');
        if (ReservedContentSlugs::isReserved($typeSlug)) {
            throw new HttpNotFoundException($request);
        }

        $type = $types->findBySlug($typeSlug);
        if ($type === null || !$type->hasPublicRoute) {
            throw new HttpNotFoundException($request);
        }

        $entry = $entries->findPublishedByTypeSlug($type->id, $entrySlug);
        if ($entry === null) {
            throw new HttpNotFoundException($request);
        }

        $fieldList = $fields->forTypeOrdered($type->id);
        $valueMap = $values->valuesByFieldIdForEntry($entry->id);
        $vd = $viewData();
        $siteBase = rtrim((string) ($vd['site_url'] ?? ''), '/');
        $fieldRows = ContentEntryViewPresenter::buildFieldRows($fieldList, $valueMap, $mediaUrls, $pdo, $siteBase);

        $featuredUrl = '';
        if ($entry->featuredImageId !== null) {
            $featuredUrl = $mediaUrls->pathForId($entry->featuredImageId);
        }
        if ($featuredUrl === '') {
            foreach ($fieldList as $f) {
                if ($f->fieldKey !== 'thumbnail_url' && $f->fieldKey !== 'card_image_url') {
                    continue;
                }
                $ext = trim((string) ($valueMap[$f->id] ?? ''));
                if ($ext === '') {
                    continue;
                }
                if (preg_match('#^https?://#i', $ext) === 1) {
                    $featuredUrl = $ext;
                    break;
                }
                if ($ext[0] === '/' || $ext[0] === '.') {
                    $featuredUrl = $ext;
                    break;
                }
            }
        }

        $pageTitle = $entry->seoTitle ?? $entry->title;
        $metaDesc = $entry->seoDescription ?? '';

        $plain = ContentEntrySeoHelper::plainDescriptionFallback($fieldList, $valueMap);
        $siteUrl = rtrim((string) (($viewData())['site_url'] ?? ''), '/');
        $path = '/' . $typeSlug . '/' . $entrySlug;
        $seoSvc = new SeoService($mediaUrls);
        $seoTwig = MetaTagBuilder::twigVars($seoSvc->resolveForContentEntry(
            $entry,
            $type,
            $path,
            $siteUrl,
            $plain,
            Settings::get('site_name') ?: null
        ));

        $tpl = ContentViewTemplates::resolve($twig->getEnvironment(), ContentViewTemplates::contentShow($type->slug));
        $threadKey = 'entry:' . $entry->id;
        $vd = $viewData();
        $viewerUid = isset($vd['phpauth_user_id']) && is_int($vd['phpauth_user_id']) ? $vd['phpauth_user_id'] : 0;
        $viewer = $viewerUid > 0 ? $viewerUid : null;
        $q = $request->getQueryParams();
        $cPage = isset($q['c_page']) && is_string($q['c_page']) && ctype_digit($q['c_page']) ? max(1, (int) $q['c_page']) : 1;
        $perRoots = max(3, min(30, (int) ($_ENV['CMS_COMMENTS_ROOTS_PER_PAGE'] ?? 10)));
        $pack = CommentRepository::loadThreadPagePackSafe($pdo, $threadKey, $cPage, $perRoots, $viewer);
        $commentTree = CommentThreadBuilder::toTree($pack['rows']);
        $basePath = '/' . $typeSlug . '/' . $entrySlug;
        $returnTo = $basePath . ($pack['page'] > 1 ? ('?c_page=' . $pack['page']) : '');
        $pager = [
            'page' => $pack['page'],
            'per_page' => $pack['per_page'],
            'total_pages' => $pack['total_pages'],
            'total_roots' => $pack['total_roots'],
            'from' => $pack['total_roots'] > 0 ? (($pack['page'] - 1) * $pack['per_page'] + 1) : 0,
            'to' => $pack['total_roots'] > 0 ? min($pack['page'] * $pack['per_page'], $pack['total_roots']) : 0,
            'base_path' => $basePath,
        ];

        $sectionsHtml = '';
        $hasSections = false;
        if ($type->supportsBlockBuilder) {
            $entrySections = new ContentEntrySectionRepository($pdo);
            $sectionManager = new SectionManager();
            $sectionRenderer = new SectionRenderer($sectionManager, new SectionTemplateResolver($sectionManager));
            $sectionRows = $entrySections->listForEntry($entry->id);
            $hasSections = $sectionRows !== [];
            $sectionsHtml = $hasSections ? $sectionRenderer->renderBlocks($twig->getEnvironment(), $sectionRows) : '';
        }

        return $twig->render($response, $tpl, array_merge($vd, $seoTwig, [
            'content_type' => $type,
            'content_entry' => $entry,
            'content_field_rows' => $fieldRows,
            'content_featured_url' => $featuredUrl,
            'content_page_title' => $pageTitle,
            'content_meta_description' => $metaDesc,
            'content_entry_has_sections' => $hasSections,
            'content_entry_sections_html' => $sectionsHtml,
            'entry_taxonomy_groups' => $entryTaxonomies->termsGroupedForEntry($entry->id),
            'comments_thread_key' => $threadKey,
            'comments_return_to' => $returnTo,
            'comments_pager_base' => $basePath,
            'comments_pager' => $pager,
            'comments_thread' => $commentTree,
        ]));
    })->setName('public.content_entry');
};
