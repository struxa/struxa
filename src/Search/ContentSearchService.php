<?php

declare(strict_types=1);

namespace App\Search;

use PDO;

/**
 * Storefront content search. Public-only: results are constrained to admin-allowed content types
 * whose {@code has_public_route = 1} and entries with {@code status = 'published'}. The user-supplied
 * term is heavily sanitized and used exclusively as a prepared LIKE parameter — never interpolated.
 *
 * @phpstan-type SearchHit array{
 *     id: int,
 *     title: string,
 *     slug: string,
 *     type_slug: string,
 *     type_name: string,
 *     snippet: string,
 *     url: string,
 *     published_at: ?string
 * }
 * @phpstan-type SearchResult array{
 *     total: int,
 *     page: int,
 *     per_page: int,
 *     total_pages: int,
 *     hits: list<SearchHit>,
 *     query: string
 * }
 */
final class ContentSearchService
{
    public const MIN_QUERY_LENGTH = 2;
    public const MAX_QUERY_LENGTH = 80;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Normalize a user-supplied query: drop control chars, collapse whitespace, cap at MAX_QUERY_LENGTH.
     * Returns '' if it does not meet MIN_QUERY_LENGTH.
     */
    public static function sanitizeQuery(string $raw): string
    {
        $q = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $raw);
        if (!is_string($q)) {
            return '';
        }
        $q = trim(preg_replace('/\s+/u', ' ', $q) ?? '');
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($q, 'UTF-8') > self::MAX_QUERY_LENGTH) {
                $q = mb_substr($q, 0, self::MAX_QUERY_LENGTH, 'UTF-8');
            }
        } elseif (strlen($q) > self::MAX_QUERY_LENGTH) {
            $q = substr($q, 0, self::MAX_QUERY_LENGTH);
        }
        if ($q === '' || (function_exists('mb_strlen') ? mb_strlen($q, 'UTF-8') : strlen($q)) < self::MIN_QUERY_LENGTH) {
            return '';
        }

        return $q;
    }

    /**
     * Escape backslash, percent and underscore so the user can't use LIKE wildcards.
     */
    public static function escapeLike(string $q): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q);
    }

    /**
     * @param list<int> $allowedTypeIds
     * @return SearchResult
     */
    public function search(string $sanitizedQuery, array $allowedTypeIds, bool $includeFieldValues, int $page, int $perPage): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(SearchSettings::PER_PAGE_MAX, $perPage));

        $empty = [
            'total' => 0,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => 0,
            'hits' => [],
            'query' => $sanitizedQuery,
        ];
        if ($sanitizedQuery === '' || $allowedTypeIds === []) {
            return $empty;
        }

        $ids = array_values(array_unique(array_map('intval', $allowedTypeIds)));
        $ids = array_values(array_filter($ids, static fn (int $i): bool => $i > 0));
        if ($ids === []) {
            return $empty;
        }

        $likeParam = '%' . self::escapeLike($sanitizedQuery) . '%';

        $typePlaceholders = implode(',', array_fill(0, count($ids), '?'));

        $matchClauses = [
            'e.title LIKE ? ESCAPE \'\\\\\'',
            'e.seo_title LIKE ? ESCAPE \'\\\\\'',
            'e.seo_description LIKE ? ESCAPE \'\\\\\'',
        ];
        $matchParams = [$likeParam, $likeParam, $likeParam];

        if ($includeFieldValues) {
            $matchClauses[] = 'EXISTS (
                SELECT 1 FROM cms_content_entry_values v
                INNER JOIN cms_content_fields f ON f.id = v.field_id
                WHERE v.content_entry_id = e.id
                  AND f.field_type IN (\'text\', \'textarea\', \'richtext\')
                  AND v.value_longtext LIKE ? ESCAPE \'\\\\\'
                LIMIT 1
            )';
            $matchParams[] = $likeParam;
        }

        $where = 'e.deleted_at IS NULL
            AND e.status = \'published\'
            AND COALESCE(e.seo_noindex, 0) = 0
            AND (e.published_at IS NULL OR e.published_at <= NOW())
            AND t.has_public_route = 1
            AND e.content_type_id IN (' . $typePlaceholders . ')
            AND (' . implode(' OR ', $matchClauses) . ')';

        $baseParams = array_merge($ids, $matchParams);

        // COUNT
        $countSql = 'SELECT COUNT(*) FROM cms_content_entries e
                     INNER JOIN cms_content_types t ON t.id = e.content_type_id
                     WHERE ' . $where;
        $countStmt = $this->pdo->prepare($countSql);
        $countStmt->execute($baseParams);
        $total = (int) $countStmt->fetchColumn();
        if ($total === 0) {
            return [
                'total' => 0,
                'page' => 1,
                'per_page' => $perPage,
                'total_pages' => 0,
                'hits' => [],
                'query' => $sanitizedQuery,
            ];
        }

        $totalPages = (int) ceil($total / $perPage);
        if ($page > $totalPages) {
            $page = $totalPages;
        }
        $offset = ($page - 1) * $perPage;

        // Order: exact title prefix > title contains > others; then most recently published.
        // Bound LIMIT/OFFSET as ints (not bound parameters) for portability.
        $sql = 'SELECT
                    e.id, e.title, e.slug, e.seo_title, e.seo_description,
                    e.published_at, e.updated_at,
                    t.slug AS type_slug, t.name AS type_name,
                    (CASE WHEN e.title LIKE ? ESCAPE \'\\\\\' THEN 1 ELSE 0 END) AS _title_match
                FROM cms_content_entries e
                INNER JOIN cms_content_types t ON t.id = e.content_type_id
                WHERE ' . $where . '
                ORDER BY _title_match DESC,
                         COALESCE(e.published_at, e.updated_at) DESC,
                         e.id DESC
                LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset;
        $params = array_merge([$likeParam], $baseParams);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $hits = [];
        while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            $typeSlug = (string) $row['type_slug'];
            $slug = (string) $row['slug'];
            $title = (string) $row['title'];
            $seoDesc = (string) ($row['seo_description'] ?? '');

            $snippet = $this->buildSnippet(
                (int) $row['id'],
                $title,
                $seoDesc,
                $sanitizedQuery,
                $includeFieldValues,
                $likeParam
            );

            $hits[] = [
                'id' => (int) $row['id'],
                'title' => $title,
                'slug' => $slug,
                'type_slug' => $typeSlug,
                'type_name' => (string) $row['type_name'],
                'snippet' => $snippet,
                'url' => '/' . rawurlencode($typeSlug) . '/' . rawurlencode($slug),
                'published_at' => isset($row['published_at']) ? (string) $row['published_at'] : null,
            ];
        }

        return [
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => $totalPages,
            'hits' => $hits,
            'query' => $sanitizedQuery,
        ];
    }

    /**
     * Pull a small text snippet around the matched term. Prefer seo_description; otherwise look in
     * a single matched field value (richtext/textarea/text) for the entry.
     */
    private function buildSnippet(int $entryId, string $title, string $seoDescription, string $query, bool $includeFieldValues, string $likeParam): string
    {
        $candidate = '';
        if ($seoDescription !== '' && self::containsCaseInsensitive($seoDescription, $query)) {
            $candidate = $seoDescription;
        } elseif ($includeFieldValues) {
            $stmt = $this->pdo->prepare(
                'SELECT v.value_longtext
                 FROM cms_content_entry_values v
                 INNER JOIN cms_content_fields f ON f.id = v.field_id
                 WHERE v.content_entry_id = ?
                   AND f.field_type IN (\'text\', \'textarea\', \'richtext\')
                   AND v.value_longtext LIKE ? ESCAPE \'\\\\\'
                 ORDER BY f.sort_order ASC
                 LIMIT 1'
            );
            $stmt->execute([$entryId, $likeParam]);
            $candidate = (string) ($stmt->fetchColumn() ?: '');
        }
        if ($candidate === '') {
            $candidate = $seoDescription !== '' ? $seoDescription : $title;
        }

        $clean = self::plainText($candidate);
        if ($clean === '') {
            return '';
        }

        return self::extractSnippet($clean, $query, 180);
    }

    public static function plainText(string $value): string
    {
        if ($value === '') {
            return '';
        }
        $value = preg_replace('#<(script|style)[^>]*>.*?</\\1>#is', ' ', $value) ?? $value;
        $value = strip_tags($value);
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    public static function extractSnippet(string $text, string $query, int $length): string
    {
        if ($text === '') {
            return '';
        }
        $useMb = function_exists('mb_stripos') && function_exists('mb_substr');
        $idx = $useMb ? mb_stripos($text, $query, 0, 'UTF-8') : stripos($text, $query);
        if ($idx === false) {
            return $useMb
                ? rtrim(mb_substr($text, 0, $length, 'UTF-8')) . (mb_strlen($text, 'UTF-8') > $length ? '…' : '')
                : rtrim(substr($text, 0, $length)) . (strlen($text) > $length ? '…' : '');
        }
        $halfWindow = max(20, (int) (($length - ($useMb ? mb_strlen($query, 'UTF-8') : strlen($query))) / 2));
        $start = max(0, $idx - $halfWindow);
        $window = $useMb
            ? mb_substr($text, $start, $length, 'UTF-8')
            : substr($text, $start, $length);
        $prefix = $start > 0 ? '…' : '';
        $textLen = $useMb ? mb_strlen($text, 'UTF-8') : strlen($text);
        $end = $start + ($useMb ? mb_strlen($window, 'UTF-8') : strlen($window));
        $suffix = $end < $textLen ? '…' : '';

        return $prefix . rtrim($window) . $suffix;
    }

    private static function containsCaseInsensitive(string $haystack, string $needle): bool
    {
        if ($needle === '') {
            return false;
        }
        if (function_exists('mb_stripos')) {
            return mb_stripos($haystack, $needle, 0, 'UTF-8') !== false;
        }

        return stripos($haystack, $needle) !== false;
    }
}
