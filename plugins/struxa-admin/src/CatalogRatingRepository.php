<?php

declare(strict_types=1);

namespace StruxaAdmin;

use PDO;

final class CatalogRatingRepository
{
    public function __construct(
        private readonly PDO $pdo,
    ) {
    }

    public function upsert(string $kind, string $slug, int $cmsUserId, int $rating): void
    {
        if (!SubmissionKind::isValid($kind) || $cmsUserId <= 0) {
            return;
        }
        $slug = self::normalizeSlug($slug);
        if ($slug === '') {
            return;
        }
        $rating = max(1, min(5, $rating));

        $stmt = $this->pdo->prepare(
            'INSERT INTO cms_struxa_catalog_ratings (kind, slug, cms_user_id, rating)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE rating = VALUES(rating), updated_at = UTC_TIMESTAMP()'
        );
        $stmt->execute([$kind, $slug, $cmsUserId, $rating]);
    }

    public function userRating(string $kind, string $slug, int $cmsUserId): ?int
    {
        if (!SubmissionKind::isValid($kind) || $cmsUserId <= 0) {
            return null;
        }
        $slug = self::normalizeSlug($slug);
        if ($slug === '') {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT rating FROM cms_struxa_catalog_ratings
             WHERE kind = ? AND slug = ? AND cms_user_id = ? LIMIT 1'
        );
        $stmt->execute([$kind, $slug, $cmsUserId]);
        $val = $stmt->fetchColumn();

        return $val !== false ? (int) $val : null;
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
            'SELECT AVG(rating) AS avg_rating, COUNT(*) AS rating_count
             FROM cms_struxa_catalog_ratings WHERE kind = ? AND slug = ?'
        );
        $stmt->execute([$kind, $slug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return ['average' => null, 'count' => 0];
        }
        $count = (int) ($row['rating_count'] ?? 0);
        if ($count <= 0) {
            return ['average' => null, 'count' => 0];
        }
        $avg = round((float) ($row['avg_rating'] ?? 0), 1);

        return ['average' => $avg, 'count' => $count];
    }

    /**
     * @param list<array{kind: string, slug: string}> $packages
     *
     * @return array<string, array{average: ?float, count: int}> keys like "plugin:forum-plugin"
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
        $sql = 'SELECT kind, slug, AVG(rating) AS avg_rating, COUNT(*) AS rating_count
                FROM cms_struxa_catalog_ratings
                WHERE ' . implode(' OR ', $placeholders) . '
                GROUP BY kind, slug';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $out = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (!is_array($row)) {
                continue;
            }
            $key = (string) ($row['kind'] ?? '') . ':' . (string) ($row['slug'] ?? '');
            $count = (int) ($row['rating_count'] ?? 0);
            $out[$key] = [
                'average' => $count > 0 ? round((float) ($row['avg_rating'] ?? 0), 1) : null,
                'count' => $count,
            ];
        }

        return $out;
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
