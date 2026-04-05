<?php

declare(strict_types=1);

namespace App\Seo;

use PDO;

final class RedirectRepository
{
    private const TABLE = 'cms_redirects';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Normalize to leading slash, no trailing slash except root.
     */
    public static function normalizePath(string $path): string
    {
        $path = '/' . ltrim(rawurldecode($path), '/');
        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }

        return $path === '' ? '/' : $path;
    }

    /**
     * @return array{id: int, from_path: string, to_url: string, status_code: int}|null
     */
    public function findByPath(string $path): ?array
    {
        $path = self::normalizePath($path);
        $stmt = $this->pdo->prepare(
            'SELECT id, from_path, to_url, status_code FROM ' . self::TABLE . ' WHERE from_path = ? LIMIT 1'
        );
        $stmt->execute([$path]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'from_path' => (string) $row['from_path'],
            'to_url' => (string) $row['to_url'],
            'status_code' => (int) $row['status_code'],
        ];
    }

    public function incrementHit(int $id): void
    {
        $stmt = $this->pdo->prepare('UPDATE ' . self::TABLE . ' SET hit_count = hit_count + 1 WHERE id = ?');
        $stmt->execute([$id]);
    }

    public function countAll(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM ' . self::TABLE)->fetchColumn();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listOrderedPage(int $limit, int $offset): array
    {
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);
        $sql = 'SELECT * FROM ' . self::TABLE . ' ORDER BY updated_at DESC LIMIT :lim OFFSET :off';
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $out = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $out[] = $row;
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function allOrdered(): array
    {
        $stmt = $this->pdo->query(
            'SELECT * FROM ' . self::TABLE . ' ORDER BY updated_at DESC'
        );
        $out = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $out[] = $row;
        }

        return $out;
    }

    public function insert(string $fromPath, string $toUrl, int $statusCode = 301): int
    {
        $fromPath = self::normalizePath($fromPath);
        $stmt = $this->pdo->prepare(
            'INSERT INTO ' . self::TABLE . ' (from_path, to_url, status_code) VALUES (?, ?, ?)'
        );
        $stmt->execute([$fromPath, $toUrl, max(300, min(399, $statusCode))]);

        return (int) $this->pdo->lastInsertId();
    }

    public function update(int $id, string $fromPath, string $toUrl, int $statusCode): void
    {
        $fromPath = self::normalizePath($fromPath);
        $stmt = $this->pdo->prepare(
            'UPDATE ' . self::TABLE . ' SET from_path = ?, to_url = ?, status_code = ? WHERE id = ?'
        );
        $stmt->execute([$fromPath, $toUrl, max(300, min(399, $statusCode)), $id]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM ' . self::TABLE . ' WHERE id = ?');
        $stmt->execute([$id]);
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ' . self::TABLE . ' WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * Replace existing redirect for path or insert.
     */
    public function upsertPath(string $fromPath, string $toUrl, int $statusCode = 301): void
    {
        $fromPath = self::normalizePath($fromPath);
        $existing = $this->findByPath($fromPath);
        if ($existing !== null) {
            $this->update((int) $existing['id'], $fromPath, $toUrl, $statusCode);
        } else {
            $this->insert($fromPath, $toUrl, $statusCode);
        }
    }
}
