<?php

declare(strict_types=1);

namespace App\Seo;

use App\Content\ContentEntryRepository;
use App\Page\PageRepository;
use App\Settings;
use PDO;

/**
 * Builds URL list for sitemap.xml (published, indexable routes only).
 */
final class SitemapService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly PageRepository $pages,
        private readonly ContentEntryRepository $entries,
    ) {
    }

    /**
     * @return list<array{loc: string, lastmod: ?string}>
     */
    public function collectUrls(string $siteUrl, ?SitemapOptions $options = null): array
    {
        $opt = $options ?? SitemapOptions::fromSettings();
        $siteUrl = rtrim($siteUrl, '/');
        $urls = [];

        $homeIdRaw = Settings::publicHomepagePageIdRaw();
        $homePageId = $homeIdRaw !== '' && ctype_digit($homeIdRaw) ? (int) $homeIdRaw : null;

        $pageRows = $opt->includePages ? $this->pages->publishedForSitemap() : [];
        $rootLastmod = null;
        if ($homePageId !== null) {
            foreach ($pageRows as $row) {
                if ((int) $row['id'] === $homePageId) {
                    $rootLastmod = $this->w3cDate($row['updated_at'] ?? '');
                    break;
                }
            }
            if ($rootLastmod === null && !$opt->includePages) {
                $home = $this->pages->findById($homePageId);
                if ($home !== null && $home->status === 'published') {
                    $rootLastmod = $this->w3cDate($home->updatedAt);
                }
            }
        }

        $urls[] = ['loc' => $siteUrl . '/', 'lastmod' => $rootLastmod];

        if ($opt->includePages) {
            foreach ($pageRows as $row) {
                if ($homePageId !== null && (int) $row['id'] === $homePageId) {
                    continue;
                }
                $urls[] = [
                    'loc' => $siteUrl . '/p/' . rawurlencode($row['slug']),
                    'lastmod' => $this->w3cDate($row['updated_at'] ?? ''),
                ];
            }
        }

        if ($opt->includeEntries) {
            foreach ($this->entries->publishedForSitemap() as $row) {
                $urls[] = [
                    'loc' => $siteUrl . '/' . rawurlencode($row['type_slug']) . '/' . rawurlencode($row['slug']),
                    'lastmod' => $this->w3cDate($row['updated_at'] ?? ''),
                ];
            }
        }

        if ($opt->includeTaxonomyArchives) {
            foreach ($this->taxonomyArchiveUrls($siteUrl) as $u) {
                $urls[] = $u;
            }
        }

        return $urls;
    }

    public function xml(string $siteUrl, ?SitemapOptions $options = null): string
    {
        $urls = $this->collectUrls($siteUrl, $options);

        $lines = ['<?xml version="1.0" encoding="UTF-8"?>', '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'];
        foreach ($urls as $u) {
            $lines[] = '  <url>';
            $lines[] = '    <loc>' . htmlspecialchars($u['loc'], ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</loc>';
            if (!empty($u['lastmod'])) {
                $lines[] = '    <lastmod>' . htmlspecialchars($u['lastmod'], ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</lastmod>';
            }
            $lines[] = '  </url>';
        }
        $lines[] = '</urlset>';

        return implode("\n", $lines) . "\n";
    }

    /**
     * @return list<array{loc: string, lastmod: ?string}>
     */
    private function taxonomyArchiveUrls(string $siteUrl): array
    {
        $sql = 'SELECT tt.slug AS term_slug, tx.slug AS tax_slug, ct.slug AS type_slug, tt.updated_at
                FROM cms_taxonomy_terms tt
                INNER JOIN cms_taxonomies tx ON tx.id = tt.taxonomy_id
                INNER JOIN cms_content_types ct ON ct.id = tx.content_type_id
                WHERE ct.has_public_route = 1 AND COALESCE(tt.seo_noindex, 0) = 0';
        $stmt = $this->pdo->query($sql);
        if ($stmt === false) {
            return [];
        }
        $out = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $out[] = [
                'loc' => $siteUrl . '/' . rawurlencode((string) $row['type_slug'])
                    . '/' . rawurlencode((string) $row['tax_slug'])
                    . '/' . rawurlencode((string) $row['term_slug']),
                'lastmod' => $this->w3cDate((string) ($row['updated_at'] ?? '')),
            ];
        }

        return $out;
    }

    private function w3cDate(string $mysqlTs): ?string
    {
        $mysqlTs = trim($mysqlTs);
        if ($mysqlTs === '') {
            return null;
        }
        $t = strtotime($mysqlTs);

        return $t !== false ? gmdate('Y-m-d', $t) : null;
    }
}
