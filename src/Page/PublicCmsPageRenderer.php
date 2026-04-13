<?php

declare(strict_types=1);

namespace App\Page;

use App\Comment\CommentRepository;
use App\Comment\CommentThreadBuilder;
use App\Media\MediaUrlHelper;
use App\Seo\ExternalLinkPolicy;
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

        $bodyHtml = ExternalLinkPolicy::maybeNofollowExternalAnchorsInHtml($page->content);
        $pageForView = $bodyHtml === $page->content ? $page : $page->withContent($bodyHtml);
        $threadKey = 'page:' . $page->id;
        $vd = $viewData();
        $viewerUid = isset($vd['phpauth_user_id']) && is_int($vd['phpauth_user_id']) ? $vd['phpauth_user_id'] : 0;
        $commentTree = CommentThreadBuilder::toTree(
            (new CommentRepository($pdo))->listApprovedForThread($threadKey, 400, $viewerUid > 0 ? $viewerUid : null)
        );

        return $twig->render($response, 'page/show.twig', array_merge($vd, $seoTwig, [
            'cms_page' => $pageForView,
            'cms_page_has_sections' => $rows !== [],
            'cms_page_sections_html' => $sectionsHtml,
            'cms_page_featured_url' => $featuredUrl,
            'cms_page_public_home' => $servedAtSiteRoot,
            'comments_thread_key' => $threadKey,
            'comments_return_to' => $servedAtSiteRoot ? '/' : '/p/' . $page->slug,
            'comments_thread' => $commentTree,
        ]));
    }
}
