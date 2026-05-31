<?php

declare(strict_types=1);

namespace App\Commerce\Catalog;

use App\Commerce\CommerceSettings;
use App\Commerce\Product\ProductCatalogEnricher;
use App\Content\ContentEntryRepository;
use App\Content\ContentEntryValueRepository;
use App\Content\ContentFieldRepository;
use App\Content\ContentTypeRepository;
use App\Content\ContentViewTemplates;
use App\Content\PublicContentIndexCardBuilder;
use App\Content\PublicContentIndexPager;
use App\Commerce\Product\ProductResolver;
use App\Media\MediaUrlHelper;
use App\Seo\MetaTagBuilder;
use App\Seo\SeoService;
use App\Settings;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpNotFoundException;
use Slim\Views\Twig;

/** Renders the core /shop catalog for the configured product content type. */
final class ShopCatalogPage
{
    public function __construct(
        private readonly CommerceSettings $commerce,
        private readonly ContentTypeRepository $types,
        private readonly ContentEntryRepository $entries,
        private readonly ContentFieldRepository $fields,
        private readonly ContentEntryValueRepository $values,
        private readonly MediaUrlHelper $mediaUrls,
        private readonly PublicContentIndexCardBuilder $indexCards,
        private readonly ProductCatalogEnricher $catalogEnricher,
    ) {
    }

    /**
     * @param callable(): array<string, mixed> $viewData
     */
    public function render(Request $request, Response $response, Twig $twig, callable $viewData): Response
    {
        if (!$this->commerce->isEnabled()) {
            throw new HttpNotFoundException($request);
        }

        $type = $this->types->findBySlug($this->commerce->productTypeSlug());
        if ($type === null || !$type->hasPublicRoute) {
            throw new HttpNotFoundException($request);
        }

        $query = $request->getQueryParams();
        $page = isset($query['page']) && is_numeric($query['page']) ? max(1, (int) $query['page']) : 1;
        $perPage = 12;
        $total = $this->entries->countPublishedForContentType($type->id);
        $totalPages = max(1, (int) ceil(max(1, $total) / $perPage));
        if ($page > $totalPages) {
            $page = $totalPages;
        }
        $rows = $this->entries->publishedForContentTypePaged($type->id, $page, $perPage);
        $indexRows = $this->catalogEnricher->enrich($type, $this->indexCards->buildForEntries($type, $rows));

        $tpl = ContentViewTemplates::resolve($twig->getEnvironment(), ContentViewTemplates::contentIndex($type->slug));
        $siteUrl = rtrim((string) (($viewData())['site_url'] ?? ''), '/');
        $seoSvc = new SeoService($this->mediaUrls);
        $shopTitle = $this->commerce->shopTitle();
        $shopDescription = $this->commerce->shopDescription() !== ''
            ? $this->commerce->shopDescription()
            : (string) ($type->description ?? 'Browse and buy products.');
        $seoTwig = MetaTagBuilder::twigVars($seoSvc->resolveForContentTypeIndex(
            $type,
            '/shop',
            $siteUrl,
            Settings::get('site_name') ?: null
        ));
        $siteName = trim(Settings::get('site_name') ?: '');
        $suffix = trim((string) Settings::get('seo_title_suffix', ''));
        $htmlTitle = $shopTitle . ($suffix !== '' ? $suffix : ($siteName !== '' ? ' — ' . $siteName : ''));
        $seoTwig['struxa_html_title'] = $htmlTitle;
        $seoTwig['struxa_og_title'] = $shopTitle;
        $seoTwig['struxa_twitter_title'] = $shopTitle;
        $seoTwig['struxa_meta_description'] = $shopDescription;
        $seoTwig['struxa_og_description'] = $shopDescription;
        $seoTwig['struxa_twitter_description'] = $shopDescription;
        $seoTwig['struxa_canonical_url'] = $siteUrl . '/shop' . ($page > 1 ? ('?page=' . $page) : '');

        return $twig->render($response, $tpl, array_merge($viewData(), $seoTwig, [
            'content_type' => $type,
            'index_entries' => $indexRows,
            'index_page' => $page,
            'index_per_page' => $perPage,
            'index_total' => $total,
            'index_total_pages' => $totalPages,
            'index_pager_items' => PublicContentIndexPager::pageItems($page, $totalPages),
            'content_index_title' => $shopTitle,
            'content_index_description' => $shopDescription,
            'commerce_shop_page' => true,
            'shop_pager_route' => 'public.commerce.shop',
        ]));
    }
}
