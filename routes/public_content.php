<?php

declare(strict_types=1);

use App\Access\MemberAccessGate;
use App\Access\MemberAccessRepository;
use App\Access\MemberAccessService;
use App\Access\RoleUserRepository;
use App\Content\ContentEntryRepository;
use App\Content\ContentEntrySeoHelper;
use App\Content\ContentEntryValueRepository;
use App\Content\ContentEntryViewPresenter;
use App\Content\ContentFieldRepository;
use App\Content\ContentTypeRepository;
use App\Content\ContentViewTemplates;
use App\Comment\CommentVisibility;
use App\Commerce\CommerceCountryCodes;
use App\Commerce\CommerceSettings;
use App\Commerce\Product\ProductResolver;
use App\Commerce\Shipping\ShippingZoneRepository;
use App\Commerce\Tax\TaxRateRepository;
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
    $commerceSettings = new CommerceSettings($pdo);
    $productResolver = new ProductResolver($pdo, $commerceSettings, $fields);
    $shippingZoneRepo = new ShippingZoneRepository($pdo);
    $taxRateRepo = new TaxRateRepository($pdo);
    $memberAccess = new MemberAccessService($pdo, new MemberAccessRepository($pdo), new RoleUserRepository($pdo));
    $memberAccessRoles = new MemberAccessRepository($pdo);

    $app->get('/{typeSlug}/{entrySlug}', function (Request $request, Response $response, array $args) use (
        $twig,
        $viewData,
        $pdo,
        $types,
        $fields,
        $entries,
        $values,
        $mediaUrls,
        $entryTaxonomies,
        $productResolver,
        $commerceSettings,
        $shippingZoneRepo,
        $taxRateRepo,
        $memberAccess,
        $memberAccessRoles
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

        $path = '/' . $typeSlug . '/' . $entrySlug;
        $roleIds = $entry->membersOnly ? $memberAccessRoles->roleIdsForEntry($entry->id) : [];
        $denied = MemberAccessGate::enforce(
            $request,
            $response,
            $twig,
            $viewData,
            $memberAccess,
            $entry->membersOnly,
            $roleIds,
            $path,
            $entry->title,
        );
        if ($denied !== null) {
            return $denied;
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
            Settings::get('site_name') ?: null,
            [
                ['name' => 'Home', 'url' => '/'],
                ['name' => $type->name, 'url' => '/' . $typeSlug],
                ['name' => $entry->title],
            ]
        ));

        $tpl = ContentViewTemplates::resolve($twig->getEnvironment(), ContentViewTemplates::contentShow($type->slug));
        $vd = $viewData();
        $viewerUid = isset($vd['phpauth_user_id']) && is_int($vd['phpauth_user_id']) ? $vd['phpauth_user_id'] : 0;
        $viewer = $viewerUid > 0 ? $viewerUid : null;
        $basePath = '/' . $typeSlug . '/' . $entrySlug;
        $commentTwig = CommentVisibility::twigVarsForContentEntry($pdo, $type, $entry->id, $basePath, $request, $viewer);

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

        $renderVars = array_merge($vd, $seoTwig, [
            'content_type' => $type,
            'content_entry' => $entry,
            'content_field_rows' => $fieldRows,
            'content_featured_url' => $featuredUrl,
            'content_page_title' => $pageTitle,
            'content_meta_description' => $metaDesc,
            'content_entry_has_sections' => $hasSections,
            'content_entry_sections_html' => $sectionsHtml,
            'entry_taxonomy_groups' => $entryTaxonomies->termsGroupedForEntry($entry->id),
            'commerce_product' => $productResolver->resolvePublished($type, $entry, $valueMap),
            'commerce_needs_checkout_country' => $commerceSettings->needsCheckoutCountry(),
            'commerce_country_choices' => CommerceCountryCodes::forSelect(array_merge(
                $shippingZoneRepo->allCountryCodes(),
                array_map(static fn ($r) => $r->countryCode, $taxRateRepo->listActive()),
            )),
        ], $commentTwig);

        if ($typeSlug === 'kb' && class_exists(\KnowledgeBasePlugin\KnowledgeBasePublicBridge::class)) {
            $renderVars = array_merge($renderVars, \KnowledgeBasePlugin\KnowledgeBasePublicBridge::showViewData($pdo, $entrySlug));
        }

        return $twig->render($response, $tpl, $renderVars);
    })->setName('public.content_entry');
};
