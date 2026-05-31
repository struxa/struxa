<?php

declare(strict_types=1);

namespace App\Search;

use App\Media\MediaRepository;
use PDO;

/**
 * Staff-facing unified search across pages, content entries, and media.
 *
 * @phpstan-type AdminSearchHit array{
 *     kind: 'page'|'entry'|'media',
 *     id: int,
 *     title: string,
 *     slug: string,
 *     status: string,
 *     snippet: string,
 *     edit_url: string,
 *     type_slug?: string,
 *     type_name?: string,
 *     type_id?: int,
 *     mime_type?: string,
 *     updated_at?: ?string
 * }
 * @phpstan-type AdminSearchResult array{
 *     total: int,
 *     page: int,
 *     per_page: int,
 *     total_pages: int,
 *     hits: list<AdminSearchHit>,
 *     query: string,
 *     pages_total: int,
 *     entries_total: int,
 *     media_total: int
 * }
 */
final class AdminContentSearchService
{
    public const MIN_QUERY_LENGTH = 2;
    public const MAX_QUERY_LENGTH = 80;
    public const PER_PAGE_DEFAULT = 20;
    public const PER_PAGE_MAX = 50;
    public const SUGGEST_PER_KIND = 5;

    public function __construct(
        private readonly PDO $pdo,
        private readonly ?MediaRepository $media = null,
    ) {
    }

    /**
     * @param array{search_pages: bool, search_entries: bool, search_media: bool} $scope
     * @return AdminSearchResult
     */
    public function search(string $sanitizedQuery, array $scope, int $page, int $perPage): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(self::PER_PAGE_MAX, $perPage));
        $searchPages = $scope['search_pages'] ?? false;
        $searchEntries = $scope['search_entries'] ?? false;
        $searchMedia = $scope['search_media'] ?? false;

        $empty = [
            'total' => 0,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => 0,
            'hits' => [],
            'query' => $sanitizedQuery,
            'pages_total' => 0,
            'entries_total' => 0,
            'media_total' => 0,
        ];

        if ($sanitizedQuery === '' || (!$searchPages && !$searchEntries && !$searchMedia)) {
            return $empty;
        }

        $likeParam = '%' . ContentSearchService::escapeLike($sanitizedQuery) . '%';
        $pageHits = $searchPages ? $this->searchPages($sanitizedQuery, $likeParam) : [];
        $entryHits = $searchEntries ? $this->searchEntries($sanitizedQuery, $likeParam) : [];
        $mediaHits = $searchMedia ? $this->searchMedia($sanitizedQuery, $likeParam) : [];

        $merged = array_merge($pageHits, $entryHits, $mediaHits);
        usort($merged, static function (array $a, array $b): int {
            $ta = $a['updated_at'] ?? '';
            $tb = $b['updated_at'] ?? '';

            return strcmp($tb, $ta);
        });

        $total = count($merged);
        if ($total === 0) {
            return $empty;
        }

        $totalPages = (int) ceil($total / $perPage);
        if ($page > $totalPages) {
            $page = $totalPages;
        }
        $offset = ($page - 1) * $perPage;

        return [
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => $totalPages,
            'hits' => array_slice($merged, $offset, $perPage),
            'query' => $sanitizedQuery,
            'pages_total' => count($pageHits),
            'entries_total' => count($entryHits),
            'media_total' => count($mediaHits),
        ];
    }

    /**
     * Compact results for command palette / typeahead (top matches per kind).
     *
     * @param array{search_pages: bool, search_entries: bool, search_media: bool} $scope
     * @return list<AdminSearchHit>
     */
    public function suggest(string $sanitizedQuery, array $scope, int $perKind = self::SUGGEST_PER_KIND): array
    {
        if ($sanitizedQuery === '') {
            return [];
        }

        $perKind = max(1, min(10, $perKind));
        $likeParam = '%' . ContentSearchService::escapeLike($sanitizedQuery) . '%';
        $hits = [];

        if ($scope['search_pages'] ?? false) {
            $hits = array_merge($hits, array_slice($this->searchPages($sanitizedQuery, $likeParam), 0, $perKind));
        }
        if ($scope['search_entries'] ?? false) {
            $hits = array_merge($hits, array_slice($this->searchEntries($sanitizedQuery, $likeParam), 0, $perKind));
        }
        if ($scope['search_media'] ?? false) {
            $hits = array_merge($hits, array_slice($this->searchMedia($sanitizedQuery, $likeParam), 0, $perKind));
        }

        usort($hits, static function (array $a, array $b): int {
            return strcmp($b['updated_at'] ?? '', $a['updated_at'] ?? '');
        });

        return $hits;
    }

    /**
     * @return list<AdminSearchHit>
     */
    private function searchPages(string $query, string $likeParam): array
    {
        $sql = 'SELECT id, title, slug, status, content, seo_description, updated_at
                FROM cms_pages
                WHERE deleted_at IS NULL
                  AND (
                    title LIKE ? ESCAPE \'\\\\\'
                    OR slug LIKE ? ESCAPE \'\\\\\'
                    OR seo_title LIKE ? ESCAPE \'\\\\\'
                    OR seo_description LIKE ? ESCAPE \'\\\\\'
                    OR content LIKE ? ESCAPE \'\\\\\'
                  )
                ORDER BY updated_at DESC, id DESC
                LIMIT 100';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$likeParam, $likeParam, $likeParam, $likeParam, $likeParam]);

        $hits = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $title = (string) $row['title'];
            $seoDesc = (string) ($row['seo_description'] ?? '');
            $content = ContentSearchService::plainText((string) ($row['content'] ?? ''));
            $candidate = $seoDesc !== '' && self::containsCaseInsensitive($seoDesc, $query)
                ? $seoDesc
                : ($content !== '' ? $content : $title);

            $hits[] = [
                'kind' => 'page',
                'id' => (int) $row['id'],
                'title' => $title,
                'slug' => (string) $row['slug'],
                'status' => (string) $row['status'],
                'snippet' => ContentSearchService::extractSnippet($candidate, $query, 160),
                'edit_url' => '',
                'updated_at' => isset($row['updated_at']) ? (string) $row['updated_at'] : null,
            ];
        }

        return $hits;
    }

    /**
     * @return list<AdminSearchHit>
     */
    private function searchEntries(string $query, string $likeParam): array
    {
        $sql = 'SELECT
                    e.id, e.title, e.slug, e.status, e.seo_description, e.updated_at,
                    e.content_type_id,
                    t.slug AS type_slug, t.name AS type_name
                FROM cms_content_entries e
                INNER JOIN cms_content_types t ON t.id = e.content_type_id
                WHERE e.deleted_at IS NULL
                  AND (
                    e.title LIKE ? ESCAPE \'\\\\\'
                    OR e.slug LIKE ? ESCAPE \'\\\\\'
                    OR e.seo_title LIKE ? ESCAPE \'\\\\\'
                    OR e.seo_description LIKE ? ESCAPE \'\\\\\'
                    OR EXISTS (
                        SELECT 1 FROM cms_content_entry_values v
                        INNER JOIN cms_content_fields f ON f.id = v.field_id
                        WHERE v.content_entry_id = e.id
                          AND f.field_type IN (\'text\', \'textarea\', \'richtext\')
                          AND v.value_longtext LIKE ? ESCAPE \'\\\\\'
                        LIMIT 1
                    )
                  )
                ORDER BY e.updated_at DESC, e.id DESC
                LIMIT 100';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$likeParam, $likeParam, $likeParam, $likeParam, $likeParam]);

        $hits = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $title = (string) $row['title'];
            $entryId = (int) $row['id'];
            $typeId = (int) $row['content_type_id'];
            $seoDesc = (string) ($row['seo_description'] ?? '');

            $hits[] = [
                'kind' => 'entry',
                'id' => $entryId,
                'type_id' => $typeId,
                'title' => $title,
                'slug' => (string) $row['slug'],
                'status' => (string) $row['status'],
                'snippet' => ContentSearchService::extractSnippet($seoDesc !== '' ? $seoDesc : $title, $query, 160),
                'edit_url' => '',
                'type_slug' => (string) $row['type_slug'],
                'type_name' => (string) $row['type_name'],
                'updated_at' => isset($row['updated_at']) ? (string) $row['updated_at'] : null,
            ];
        }

        return $hits;
    }

    /**
     * @return list<AdminSearchHit>
     */
    private function searchMedia(string $query, string $likeParam): array
    {
        $repo = $this->media ?? new MediaRepository($this->pdo);
        $rows = $repo->adminSearchLike($likeParam, 50);
        $hits = [];
        foreach ($rows as $row) {
            $name = (string) ($row['original_name'] ?? $row['filename'] ?? '');
            $mime = (string) ($row['mime_type'] ?? '');
            $hits[] = [
                'kind' => 'media',
                'id' => (int) $row['id'],
                'title' => $name,
                'slug' => (string) ($row['filename'] ?? ''),
                'status' => $mime !== '' ? $mime : 'file',
                'snippet' => ContentSearchService::extractSnippet($name, $query, 120),
                'edit_url' => '',
                'mime_type' => $mime,
                'updated_at' => isset($row['created_at']) ? (string) $row['created_at'] : null,
            ];
        }

        return $hits;
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
