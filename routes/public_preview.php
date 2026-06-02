<?php

declare(strict_types=1);

use App\Comment\CommentVisibility;
use App\Content\ContentEntryRepository;
use App\Content\ContentEntrySeoHelper;
use App\Content\ContentEntryValueRepository;
use App\Content\ContentEntryViewPresenter;
use App\Content\ContentFieldRepository;
use App\Content\ContentTypeRepository;
use App\Content\ContentViewTemplates;
use App\Content\ReservedContentSlugs;
use App\Media\MediaUrlHelper;
use App\Page\PageRepository;
use App\Page\PublicCmsPageRenderer;
use App\Preview\PreviewTokenRepository;
use App\Seo\MetaTagBuilder;
use App\Seo\SeoService;
use App\Settings;
use App\Taxonomy\ContentEntryTaxonomyRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Slim\Exception\HttpNotFoundException;
use Slim\Views\Twig;

/**
 * Token-gated previews for stakeholders (no CMS login required).
 */
return static function (App $app, Twig $twig, \PDO $pdo, callable $viewData): void {
    $tokens = new PreviewTokenRepository($pdo);
    $pages = new PageRepository($pdo);
    $types = new ContentTypeRepository($pdo);
    $fields = new ContentFieldRepository($pdo);
    $entries = new ContentEntryRepository($pdo);
    $values = new ContentEntryValueRepository($pdo);
    $mediaUrls = new MediaUrlHelper($pdo);
    $entryTaxonomies = new ContentEntryTaxonomyRepository($pdo);

    $previewHeaders = static function (Response $r): Response {
        return $r
            ->withHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')
            ->withHeader('Cache-Control', 'private, no-store, must-revalidate')
            ->withHeader('Pragma', 'no-cache');
    };

    $app->get('/preview/page/{id:[0-9]+}', function (Request $request, Response $response, array $args) use (
        $twig,
        $viewData,
        $pdo,
        $tokens,
        $pages,
        $previewHeaders
    ): Response {
        $id = (int) ($args['id'] ?? 0);
        $token = trim((string) ($request->getQueryParams()['token'] ?? ''));
        $row = $tokens->verify($token);
        if ($row === null || $row['subject_type'] !== 'page' || $row['subject_id'] !== $id) {
            throw new HttpNotFoundException($request);
        }
        $page = $pages->findById($id);
        if ($page === null) {
            throw new HttpNotFoundException($request);
        }

        $out = PublicCmsPageRenderer::render(
            $twig,
            $response,
            $viewData,
            $pdo,
            $page,
            '/p/' . $page->slug,
            false,
            $request,
            true,
        );

        return $previewHeaders($out);
    })->setName('public.preview.page');

    $app->get('/preview/content-entry/{entryId:[0-9]+}', function (Request $request, Response $response, array $args) use (
        $twig,
        $viewData,
        $pdo,
        $tokens,
        $types,
        $fields,
        $entries,
        $values,
        $mediaUrls,
        $entryTaxonomies,
        $previewHeaders
    ): Response {
        $entryId = (int) ($args['entryId'] ?? 0);
        $token = trim((string) ($request->getQueryParams()['token'] ?? ''));
        $row = $tokens->verify($token);
        if ($row === null || $row['subject_type'] !== 'content_entry' || $row['subject_id'] !== $entryId) {
            throw new HttpNotFoundException($request);
        }

        $entry = $entries->findById($entryId);
        if ($entry === null) {
            throw new HttpNotFoundException($request);
        }
        $type = $types->findById($entry->contentTypeId);
        if ($type === null || !$type->hasPublicRoute || ReservedContentSlugs::isReserved($type->slug)) {
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
        $path = '/' . $type->slug . '/' . $entry->slug;
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
        $vd = $viewData();
        $viewerUid = isset($vd['phpauth_user_id']) && is_int($vd['phpauth_user_id']) ? $vd['phpauth_user_id'] : 0;
        $viewer = $viewerUid > 0 ? $viewerUid : null;
        $basePath = $path;
        $commentTwig = CommentVisibility::twigVarsForContentEntry($pdo, $type, $entry->id, $basePath, null, $viewer);

        $sectionsHtml = '';
        $hasSections = false;
        if ($type->supportsBlockBuilder) {
            $entrySections = new \App\Section\ContentEntrySectionRepository($pdo);
            $sectionManager = new \App\Section\SectionManager();
            $sectionRenderer = new \App\Section\SectionRenderer($sectionManager, new \App\Section\SectionTemplateResolver($sectionManager));
            $sectionRows = $entrySections->listForEntry($entry->id);
            $hasSections = $sectionRows !== [];
            $sectionsHtml = $hasSections ? $sectionRenderer->renderBlocks($twig->getEnvironment(), $sectionRows) : '';
        }

        $out = $twig->render($response, $tpl, array_merge($vd, $seoTwig, [
            'content_type' => $type,
            'content_entry' => $entry,
            'content_field_rows' => $fieldRows,
            'content_featured_url' => $featuredUrl,
            'content_page_title' => $pageTitle,
            'content_meta_description' => $metaDesc,
            'content_entry_has_sections' => $hasSections,
            'content_entry_sections_html' => $sectionsHtml,
            'entry_taxonomy_groups' => $entryTaxonomies->termsGroupedForEntry($entry->id),
            'cms_content_preview' => true,
        ], $commentTwig));

        return $previewHeaders($out);
    })->setName('public.preview.content_entry');
};
