<?php

declare(strict_types=1);

namespace App\Analytics;

use PDO;

final class ShortLinkRepository
{
    private const TABLE = 'cms_short_links';

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function findByCode(string $code): ?ShortLink
    {
        $code = self::normalizeCode($code);
        if ($code === '') {
            return null;
        }
        $stmt = $this->pdo->prepare(
            'SELECT id, code, destination_url, label, clicks, created_by, created_at, updated_at
             FROM ' . self::TABLE . ' WHERE code = ? LIMIT 1'
        );
        $stmt->execute([$code]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? ShortLink::fromRow($row) : null;
    }

    public function codeExists(string $code, ?int $exceptId = null): bool
    {
        $code = self::normalizeCode($code);
        if ($code === '') {
            return false;
        }
        $sql = 'SELECT 1 FROM ' . self::TABLE . ' WHERE code = ?';
        $params = [$code];
        if ($exceptId !== null && $exceptId > 0) {
            $sql .= ' AND id != ?';
            $params[] = $exceptId;
        }
        $sql .= ' LIMIT 1';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return (bool) $stmt->fetchColumn();
    }

    /**
     * @return list<ShortLink>
     */
    public function listRecent(int $limit = 100): array
    {
        $limit = max(1, min(200, $limit));
        $stmt = $this->pdo->query(
            'SELECT id, code, destination_url, label, clicks, created_by, created_at, updated_at
             FROM ' . self::TABLE . ' ORDER BY created_at DESC LIMIT ' . $limit
        );
        $out = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (is_array($row)) {
                $out[] = ShortLink::fromRow($row);
            }
        }

        return $out;
    }

    public function insert(string $code, string $destinationUrl, ?string $label, ?int $createdBy): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO ' . self::TABLE . ' (code, destination_url, label, created_by)
             VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([
            self::normalizeCode($code),
            self::truncate($destinationUrl, 2048),
            $label !== null && $label !== '' ? self::truncate($label, 255) : null,
            $createdBy !== null && $createdBy > 0 ? $createdBy : null,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function delete(int $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM ' . self::TABLE . ' WHERE id = ?');

        return $stmt->execute([$id]) && $stmt->rowCount() > 0;
    }

    public function recordClick(int $id): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE ' . self::TABLE . ' SET clicks = clicks + 1, updated_at = UTC_TIMESTAMP() WHERE id = ?'
        );
        $stmt->execute([$id]);
    }

    public static function normalizeCode(string $code): string
    {
        return strtolower(trim($code));
    }

    private static function truncate(string $value, int $max): string
    {
        return mb_strlen($value) > $max ? mb_substr($value, 0, $max) : $value;
    }
}
