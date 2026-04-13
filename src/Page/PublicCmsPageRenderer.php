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
        $viewer = $viewerUid > 0 ? $viewerUid : null;
        $cPage = self::commentsPageFromRequest($request);
        $perRoots = self::commentsRootsPerPage();
        $pack = CommentRepository::loadThreadPagePackSafe($pdo, $threadKey, $cPage, $perRoots, $viewer);
        $commentTree = CommentThreadBuilder::toTree($pack['rows']);
        $basePath = $servedAtSiteRoot ? '/' : '/p/' . $page->slug;
        $returnTo = $basePath . ($pack['page'] > 1 ? ('?c_page=' . $pack['page']) : '');

        return $twig->render($response, 'page/show.twig', array_merge($vd, $seoTwig, [
            'cms_page' => $pageForView,
            'cms_page_has_sections' => $rows !== [],
            'cms_page_sections_html' => $sectionsHtml,
            'cms_page_featured_url' => $featuredUrl,
            'cms_page_public_home' => $servedAtSiteRoot,
            'comments_thread_key' => $threadKey,
            'comments_return_to' => $returnTo,
            'comments_pager_base' => $basePath,
            'comments_pager' => self::commentsPagerTwigVars($pack, $basePath),
            'comments_thread' => $commentTree,
        ]));
    }

    private static function commentsPageFromRequest(?ServerRequestInterface $request): int
    {
        if ($request === null) {
            return 1;
        }
        $q = $request->getQueryParams();
        $raw = isset($q['c_page']) && is_string($q['c_page']) && ctype_digit($q['c_page']) ? (int) $q['c_page'] : 1;

        return max(1, $raw);
    }

    private static function commentsRootsPerPage(): int
    {
        $n = (int) ($_ENV['CMS_COMMENTS_ROOTS_PER_PAGE'] ?? 10);

        return max(3, min(30, $n));
    }

    /**
     * @param array{rows: list<array<string, mixed>>, total_roots: int, page: int, per_page: int, total_pages: int} $pack
     *
     * @return array<string, int|string>
     */
    private static function commentsPagerTwigVars(array $pack, string $basePath): array
    {
        $total = (int) $pack['total_roots'];
        $page = (int) $pack['page'];
        $per = (int) $pack['per_page'];
        $pages = (int) $pack['total_pages'];
        $from = $total > 0 ? (($page - 1) * $per + 1) : 0;
        $to = $total > 0 ? min($page * $per, $total) : 0;

        return [
            'page' => $page,
            'per_page' => $per,
            'total_pages' => $pages,
            'total_roots' => $total,
            'from' => $from,
            'to' => $to,
            'base_path' => $basePath,
        ];
    }
}
