<?php

declare(strict_types=1);

namespace StruxaAdmin;

use PDO;

final class CatalogReviewRepository
{
    public const MAX_BODY_LENGTH = 2000;

    public const PER_PAGE = 10;

    public function __construct(
        private readonly PDO $pdo,
    ) {
    }

    public function upsert(string $kind, string $slug, int $cmsUserId, int $rating, string $body): void
    {
        if (!SubmissionKind::isValid($kind) || $cmsUserId <= 0) {
            return;
        }
        $slug = self::normalizeSlug($slug);
        if ($slug === '') {
            return;
        }
        $rating = max(1, min(5, $rating));
        $body = trim($body);
        if ($body === '' || mb_strlen($body) > self::MAX_BODY_LENGTH) {
            return;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO cms_struxa_catalog_reviews (kind, slug, cms_user_id, rating, body)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE rating = VALUES(rating), body = VALUES(body), updated_at = UTC_TIMESTAMP()'
        );
        $stmt->execute([$kind, $slug, $cmsUserId, $rating, $body]);
    }

    /**
     * @return ?array{rating: int, body: string, created_at: string, updated_at: string}
     */
    public function userReview(string $kind, string $slug, int $cmsUserId): ?array
    {
        if (!SubmissionKind::isValid($kind) || $cmsUserId <= 0) {
            return null;
        }
        $slug = self::normalizeSlug($slug);
        if ($slug === '') {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT rating, body, created_at, updated_at
             FROM cms_struxa_catalog_reviews
             WHERE kind = ? AND slug = ? AND cms_user_id = ?
             LIMIT 1'
        );
        $stmt->execute([$kind, $slug, $cmsUserId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        return [
            'rating' => (int) ($row['rating'] ?? 0),
            'body' => (string) ($row['body'] ?? ''),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }

    public function countFor(string $kind, string $slug): int
    {
        if (!SubmissionKind::isValid($kind)) {
            return 0;
        }
        $slug = self::normalizeSlug($slug);
        if ($slug === '') {
            return 0;
        }

        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM cms_struxa_catalog_reviews
             WHERE kind = ? AND slug = ? AND TRIM(body) <> \'\''
        );
        $stmt->execute([$kind, $slug]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @return array{average: ?float, count: int}
     */
    public function statsFor(string $kind, string $slug): array
    {
        if (!SubmissionKind::isValid($kind)) {
            return ['average' => null, 'count' => 0];
        }
        $slug = self::normalizeSlug($slug);
        if ($slug === '') {
            return ['average' => null, 'count' => 0];
        }

        $stmt = $this->pdo->prepare(
            'SELECT AVG(rating) AS avg_rating, COUNT(*) AS review_count
             FROM cms_struxa_catalog_reviews
             WHERE kind = ? AND slug = ? AND TRIM(body) <> \'\''
        );
        $stmt->execute([$kind, $slug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return ['average' => null, 'count' => 0];
        }
        $count = (int) ($row['review_count'] ?? 0);
        if ($count <= 0) {
            return ['average' => null, 'count' => 0];
        }

        return [
            'average' => round((float) ($row['avg_rating'] ?? 0), 1),
            'count' => $count,
        ];
    }

    /**
     * @param list<array{kind: string, slug: string}> $packages
     *
     * @return array<string, array{average: ?float, count: int}>
     */
    public function statsForPackages(array $packages): array
    {
        if ($packages === []) {
            return [];
        }

        $keys = [];
        $params = [];
        foreach ($packages as $pkg) {
            $kind = (string) ($pkg['kind'] ?? '');
            $slug = self::normalizeSlug((string) ($pkg['slug'] ?? ''));
            if (!SubmissionKind::isValid($kind) || $slug === '') {
                continue;
            }
            $key = $kind . ':' . $slug;
            if (isset($keys[$key])) {
                continue;
            }
            $keys[$key] = true;
            $params[] = $kind;
            $params[] = $slug;
        }
        if ($params === []) {
            return [];
        }

        $placeholders = [];
        for ($i = 0, $n = count($params) / 2; $i < $n; ++$i) {
            $placeholders[] = '(kind = ? AND slug = ?)';
        }
        $sql = 'SELECT kind, slug, AVG(rating) AS avg_rating, COUNT(*) AS review_count
                FROM cms_struxa_catalog_reviews
                WHERE TRIM(body) <> \'\' AND (' . implode(' OR ', $placeholders) . ')
                GROUP BY kind, slug';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $out = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (!is_array($row)) {
                continue;
            }
            $key = (string) ($row['kind'] ?? '') . ':' . (string) ($row['slug'] ?? '');
            $count = (int) ($row['review_count'] ?? 0);
            $out[$key] = [
                'average' => $count > 0 ? round((float) ($row['avg_rating'] ?? 0), 1) : null,
                'count' => $count,
            ];
        }

        return $out;
    }

    /**
     * @return array{
     *   items: list<array{cms_user_id: int, rating: int, body: string, created_at: string, updated_at: string}>,
     *   total: int,
     *   page: int,
     *   pages: int,
     *   per_page: int
     * }
     */
    public function listPage(string $kind, string $slug, int $page, int $perPage = self::PER_PAGE): array
    {
        if (!SubmissionKind::isValid($kind)) {
            return ['items' => [], 'total' => 0, 'page' => 1, 'pages' => 0, 'per_page' => $perPage];
        }
        $slug = self::normalizeSlug($slug);
        if ($slug === '') {
            return ['items' => [], 'total' => 0, 'page' => 1, 'pages' => 0, 'per_page' => $perPage];
        }
        $perPage = max(1, min(50, $perPage));
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;

        $countStmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM cms_struxa_catalog_reviews
             WHERE kind = ? AND slug = ? AND TRIM(body) <> \'\''
        );
        $countStmt->execute([$kind, $slug]);
        $total = (int) $countStmt->fetchColumn();
        $pages = $total > 0 ? (int) ceil($total / $perPage) : 0;
        if ($pages > 0 && $page > $pages) {
            $page = $pages;
            $offset = ($page - 1) * $perPage;
        }

        $stmt = $this->pdo->prepare(
            'SELECT cms_user_id, rating, body, created_at, updated_at
             FROM cms_struxa_catalog_reviews
             WHERE kind = ? AND slug = ? AND TRIM(body) <> \'\'
             ORDER BY updated_at DESC
             LIMIT ' . $perPage . ' OFFSET ' . $offset
        );
        $stmt->execute([$kind, $slug]);

        $items = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (!is_array($row)) {
                continue;
            }
            $items[] = [
                'cms_user_id' => (int) ($row['cms_user_id'] ?? 0),
                'rating' => (int) ($row['rating'] ?? 0),
                'body' => (string) ($row['body'] ?? ''),
                'created_at' => (string) ($row['created_at'] ?? ''),
                'updated_at' => (string) ($row['updated_at'] ?? ''),
            ];
        }

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'per_page' => $perPage,
        ];
    }

    private static function normalizeSlug(string $slug): string
    {
        $slug = strtolower(trim($slug));
        if ($slug === '' || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
            return '';
        }

        return $slug;
    }
}
