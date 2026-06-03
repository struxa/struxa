<?php

declare(strict_types=1);

namespace StruxaAdmin;

use PDO;

final class CatalogDownloadStatsRepository
{
    public function __construct(
        private readonly PDO $pdo,
    ) {
    }

    public function recordDownload(string $kind, string $slug): void
    {
        if (!SubmissionKind::isValid($kind)) {
            return;
        }
        $slug = strtolower(trim($slug));
        if ($slug === '' || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
            return;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO cms_struxa_catalog_download_stats (kind, slug, download_count, last_download_at)
             VALUES (?, ?, 1, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE
                 download_count = download_count + 1,
                 last_download_at = UTC_TIMESTAMP()'
        );
        $stmt->execute([$kind, $slug]);
    }

    public function countFor(string $kind, string $slug): int
    {
        if (!SubmissionKind::isValid($kind)) {
            return 0;
        }
        $slug = strtolower(trim($slug));
        $stmt = $this->pdo->prepare(
            'SELECT download_count FROM cms_struxa_catalog_download_stats WHERE kind = ? AND slug = ? LIMIT 1'
        );
        $stmt->execute([$kind, $slug]);
        $val = $stmt->fetchColumn();

        return $val !== false ? (int) $val : 0;
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
            $slug = strtolower(trim((string) ($pkg['slug'] ?? '')));
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
        $sql = 'SELECT kind, slug, download_count FROM cms_struxa_catalog_download_stats WHERE '
            . implode(' OR ', $placeholders);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $out = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (!is_array($row)) {
                continue;
            }
            $k = (string) ($row['kind'] ?? '') . ':' . (string) ($row['slug'] ?? '');
            $out[$k] = (int) ($row['download_count'] ?? 0);
        }

        return $out;
    }
}
