<?php

declare(strict_types=1);

namespace App\Page;

use App\Comment\CommentVisibility;
use App\Access\MemberAccessGate;
use App\Access\MemberAccessRepository;
use App\Access\MemberAccessService;
use App\Access\RoleUserRepository;
use App\Filter\FilterHook;
use App\Filter\Filters;
use App\Media\MediaUrlHelper;
use App\Section\PageSectionRepository;
use App\Section\SectionManager;
use App\Section\SectionRenderer;
use App\Section\SectionTemplateResolver;
use App\Seo\ExternalLinkPolicy;
use App\Seo\MetaTagBuilder;
use App\Seo\SeoService;
use App\Settings;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
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
        ?ServerRequestInterface $request = null,
        bool $bypassMemberAccess = false,
    ): ResponseInterface {
        if (!$bypassMemberAccess && $request !== null) {
            $memberAccess = new MemberAccessService($pdo, new MemberAccessRepository($pdo), new RoleUserRepository($pdo));
            $roleIds = $page->membersOnly ? (new MemberAccessRepository($pdo))->roleIdsForPage($page->id) : [];
            $denied = MemberAccessGate::enforce(
                $request,
                $response,
                $twig,
                $viewData,
                $memberAccess,
                $page->membersOnly,
                $roleIds,
                $pathForSeoFromRoot,
                $page->title,
            );
            if ($denied !== null) {
                return $denied;
            }
        }

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
            Settings::get('site_name') ?: null,
            [
                ['name' => 'Home', 'url' => '/'],
                ['name' => $page->title],
            ]
        ));

        $bodyHtml = ExternalLinkPolicy::maybeNofollowExternalAnchorsInHtml($page->content);
        $bodyHtml = (string) Filters::apply(FilterHook::PAGE_RENDER, $bodyHtml, [
            'page_id' => $page->id,
            'slug' => $page->slug,
            'subject' => 'page',
        ]);
        $pageForView = $bodyHtml === $page->content ? $page : $page->withContent($bodyHtml);
        $vd = $viewData();
        $viewerUid = isset($vd['phpauth_user_id']) && is_int($vd['phpauth_user_id']) ? $vd['phpauth_user_id'] : 0;
        $viewer = $viewerUid > 0 ? $viewerUid : null;
        $basePath = $servedAtSiteRoot ? '/' : '/p/' . $page->slug;
        $commentTwig = CommentVisibility::twigVarsForPage($pdo, $page, $basePath, $servedAtSiteRoot, $request, $viewer);

        return $twig->render($response, 'page/show.twig', array_merge($vd, $seoTwig, [
            'cms_page' => $pageForView,
            'cms_page_has_sections' => $rows !== [],
            'cms_page_sections_html' => $sectionsHtml,
            'cms_page_featured_url' => $featuredUrl,
            'cms_page_public_home' => $servedAtSiteRoot,
        ], $commentTwig));
    }
}
