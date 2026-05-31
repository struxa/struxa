<?php

declare(strict_types=1);

namespace App\Seo;

use PDO;

/**
 * Suggests internal links to other published content that matches the focus keyphrase.
 */
final class SeoInternalLinkSuggester
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return list<array{title: string, url: string, kind: string}>
     */
    public function suggest(string $focusKeyphrase, string $currentContentPlain, ?int $exceptPageId = null, ?int $exceptEntryId = null, int $limit = 5): array
    {
        $phrase = self::normalizePhrase($focusKeyphrase);
        if ($phrase === '') {
            return [];
        }
        $limit = max(1, min(10, $limit));
        $out = [];
        $linkedUrls = self::extractLinkedPaths($currentContentPlain);

        $pageSql = "SELECT id, title, slug FROM cms_pages
            WHERE deleted_at IS NULL AND status = 'published' AND (published_at IS NULL OR published_at <= NOW(6))
            AND (title LIKE ? OR content LIKE ? OR seo_title LIKE ? OR seo_description LIKE ?)";
        $params = ["%{$phrase}%", "%{$phrase}%", "%{$phrase}%", "%{$phrase}%"];
        if ($exceptPageId !== null) {
            $pageSql .= ' AND id != ?';
            $params[] = $exceptPageId;
        }
        $pageSql .= ' ORDER BY updated_at DESC LIMIT ' . (int) $limit;
        $stmt = $this->pdo->prepare($pageSql);
        $stmt->execute($params);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $url = '/p/' . (string) $row['slug'];
            if (self::alreadyLinked($linkedUrls, $url)) {
                continue;
            }
            $out[] = [
                'title' => (string) $row['title'],
                'url' => $url,
                'kind' => 'page',
            ];
        }

        if (count($out) >= $limit) {
            return array_slice($out, 0, $limit);
        }

        $entrySql = "SELECT e.id, e.title, e.slug, t.slug AS type_slug, t.name AS type_name
            FROM cms_content_entries e
            INNER JOIN cms_content_types t ON t.id = e.content_type_id
            WHERE e.deleted_at IS NULL AND e.status = 'published' AND (e.published_at IS NULL OR e.published_at <= NOW(6))
            AND t.has_public_route = 1
            AND (e.title LIKE ? OR e.seo_title LIKE ? OR e.seo_description LIKE ?)";
        $entryParams = ["%{$phrase}%", "%{$phrase}%", "%{$phrase}%"];
        if ($exceptEntryId !== null) {
            $entrySql .= ' AND e.id != ?';
            $entryParams[] = $exceptEntryId;
        }
        $entrySql .= ' ORDER BY e.updated_at DESC LIMIT ' . (int) ($limit * 2);
        $stmt = $this->pdo->prepare($entrySql);
        $stmt->execute($entryParams);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (count($out) >= $limit) {
                break;
            }
            $url = '/' . (string) $row['type_slug'] . '/' . (string) $row['slug'];
            if (self::alreadyLinked($linkedUrls, $url)) {
                continue;
            }
            $out[] = [
                'title' => (string) $row['title'],
                'url' => $url,
                'kind' => (string) $row['type_name'],
            ];
        }

        return array_slice($out, 0, $limit);
    }

    private static function normalizePhrase(string $phrase): string
    {
        $phrase = mb_strtolower(trim(preg_replace('/\s+/', ' ', $phrase) ?? ''));

        return mb_strlen($phrase) >= 2 ? $phrase : '';
    }

    /**
     * @return list<string>
     */
    private static function extractLinkedPaths(string $htmlOrPlain): array
    {
        $paths = [];
        if (preg_match_all('#href=["\'](/[^"\']+)["\']#i', $htmlOrPlain, $m) === 1) {
            foreach ($m[1] as $p) {
                $paths[] = strtolower(rtrim((string) $p, '/'));
            }
        }

        return $paths;
    }

    /**
     * @param list<string> $linked
     */
    private static function alreadyLinked(array $linked, string $url): bool
    {
        $norm = strtolower(rtrim($url, '/'));

        return in_array($norm, $linked, true);
    }
}
