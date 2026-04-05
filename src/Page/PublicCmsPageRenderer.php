<?php

declare(strict_types=1);

namespace App\Page;

use App\Media\MediaUrlHelper;
use App\Section\PageSectionRepository;
use App\Section\SectionManager;
use App\Section\SectionRenderer;
use App\Section\SectionTemplateResolver;
use App\Seo\MetaTagBuilder;
use App\Seo\SeoService;
use App\Settings;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Slim\Views\Twig;

/**
 * Shared public render for CMS pages (GET /p/{slug} and optional GET / when a page is set as home).
 */
final class PublicCmsPageRenderer
{
    /**
     * @param callable(): array<string, mixed> $viewData
     */
    public static function render(
        Twig $twig,
        ResponseInterface $response,
        callable $viewData,
        PDO $pdo,
        Page $page,
        string $pathForSeoFromRoot,
        bool $servedAtSiteRoot,
    ): ResponseInterface {
        $pageSections = new PageSectionRepository($pdo);
        $sectionManager = new SectionManager();
        $sectionRenderer = new SectionRenderer($sectionManager, new SectionTemplateResolver($sectionManager));

        $rows = $pageSections->listForPage($page->id);
        $sectionsHtml = $rows !== [] ? $sectionRenderer->renderPage($twig->getEnvironment(), $rows) : '';
        $featuredUrl = '';
        if ($page->featuredImageId !== null) {
            $featuredUrl = (new MediaUrlHelper($pdo))->pathForId($page->featuredImageId);
        }

        $siteUrl = rtrim((string) (($viewData())['site_url'] ?? ''), '/');
        $seoSvc = new SeoService(new MediaUrlHelper($pdo));
        $seoTwig = MetaTagBuilder::twigVars($seoSvc->resolveForPage(
            $page,
            $pathForSeoFromRoot,
            $siteUrl,
            Settings::get('site_name') ?: null
        ));

        return $twig->render($response, 'page/show.twig', array_merge($viewData(), $seoTwig, [
            'cms_page' => $page,
            'cms_page_has_sections' => $rows !== [],
            'cms_page_sections_html' => $sectionsHtml,
            'cms_page_featured_url' => $featuredUrl,
            'cms_page_public_home' => $servedAtSiteRoot,
        ]));
    }
}
