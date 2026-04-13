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
    $comments = new CommentRepository($pdo);

    $app->get('/{typeSlug}/{entrySlug}', function (Request $request, Response $response, array $args) use (
        $twig,
        $viewData,
        $types,
        $fields,
        $entries,
        $values,
        $mediaUrls,
        $entryTaxonomies,
        $comments
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
        $fieldRows = ContentEntryViewPresenter::buildFieldRows($fieldList, $valueMap, $mediaUrls);

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
        $commentRows = $comments->listApprovedForThread($threadKey, 400, $viewerUid > 0 ? $viewerUid : null);
        $commentTree = CommentThreadBuilder::toTree($commentRows);

        return $twig->render($response, $tpl, array_merge($vd, $seoTwig, [
            'content_type' => $type,
            'content_entry' => $entry,
            'content_field_rows' => $fieldRows,
            'content_featured_url' => $featuredUrl,
            'content_page_title' => $pageTitle,
            'content_meta_description' => $metaDesc,
            'entry_taxonomy_groups' => $entryTaxonomies->termsGroupedForEntry($entry->id),
            'comments_thread_key' => $threadKey,
            'comments_return_to' => '/' . $typeSlug . '/' . $entrySlug,
            'comments_thread' => $commentTree,
        ]));
    })->setName('public.content_entry');
};
