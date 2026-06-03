<?php

declare(strict_types=1);

namespace StruxaAdmin;

use PDO;

final class CatalogCommentRepository
{
    public const MAX_BODY_LENGTH = 2000;

    public function __construct(
        private readonly PDO $pdo,
    ) {
    }

    public function insert(string $kind, string $slug, int $cmsUserId, string $body): ?int
    {
        if (!SubmissionKind::isValid($kind) || $cmsUserId <= 0) {
            return null;
        }
        $slug = self::normalizeSlug($slug);
        if ($slug === '') {
            return null;
        }
        $body = trim($body);
        if ($body === '' || mb_strlen($body) > self::MAX_BODY_LENGTH) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO cms_struxa_catalog_comments (kind, slug, cms_user_id, body)
             VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$kind, $slug, $cmsUserId, $body]);

        return (int) $this->pdo->lastInsertId();
    }

    public function countVisible(string $kind, string $slug): int
    {
        if (!SubmissionKind::isValid($kind)) {
            return 0;
        }
        $slug = self::normalizeSlug($slug);
        if ($slug === '') {
            return 0;
        }

        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM cms_struxa_catalog_comments
             WHERE kind = ? AND slug = ? AND status = ?'
        );
        $stmt->execute([$kind, $slug, 'visible']);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @param list<array{kind: string, slug: string}> $packages
     *
     * @return array<string, int> keys like "plugin:forum-plugin"
     */
    public function countsForPackages(array $packages): array
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
        $sql = 'SELECT kind, slug, COUNT(*) AS comment_count
                FROM cms_struxa_catalog_comments
                WHERE status = ? AND (' . implode(' OR ', $placeholders) . ')
                GROUP BY kind, slug';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_merge(['visible'], $params));

        $out = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (!is_array($row)) {
                continue;
            }
            $key = (string) ($row['kind'] ?? '') . ':' . (string) ($row['slug'] ?? '');
            $out[$key] = (int) ($row['comment_count'] ?? 0);
        }

        return $out;
    }

    /**
     * @return list<array{id: int, cms_user_id: int, body: string, created_at: string}>
     */
    public function listVisible(string $kind, string $slug, int $limit = 50): array
    {
        if (!SubmissionKind::isValid($kind)) {
            return [];
        }
        $slug = self::normalizeSlug($slug);
        if ($slug === '') {
            return [];
        }
        $limit = max(1, min(100, $limit));

        $stmt = $this->pdo->prepare(
            'SELECT id, cms_user_id, body, created_at
             FROM cms_struxa_catalog_comments
             WHERE kind = ? AND slug = ? AND status = ?
             ORDER BY created_at DESC
             LIMIT ' . $limit
        );
        $stmt->execute([$kind, $slug, 'visible']);

        $out = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (!is_array($row)) {
                continue;
            }
            $out[] = [
                'id' => (int) ($row['id'] ?? 0),
                'cms_user_id' => (int) ($row['cms_user_id'] ?? 0),
                'body' => (string) ($row['body'] ?? ''),
                'created_at' => (string) ($row['created_at'] ?? ''),
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
