<?php

declare(strict_types=1);

namespace App\Content\List;

use PDO;

final class ContentListRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listForAdmin(): array
    {
        $stmt = $this->pdo->query(
            'SELECT l.*, t.name AS type_name, t.slug AS type_slug
             FROM cms_content_lists l
             INNER JOIN cms_content_types t ON t.id = l.content_type_id
             ORDER BY l.name ASC'
        );
        if ($stmt === false) {
            return [];
        }

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findById(int $id): ?array
    {
        if ($id < 1) {
            return null;
        }
        $stmt = $this->pdo->prepare(
            'SELECT l.*, t.name AS type_name, t.slug AS type_slug, t.has_public_route
             FROM cms_content_lists l
             INNER JOIN cms_content_types t ON t.id = l.content_type_id
             WHERE l.id = ? LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    public function findActiveBySlug(string $slug): ?array
    {
        $slug = strtolower(trim($slug));
        if ($slug === '') {
            return null;
        }
        $stmt = $this->pdo->prepare(
            'SELECT l.*, t.name AS type_name, t.slug AS type_slug, t.has_public_route
             FROM cms_content_lists l
             INNER JOIN cms_content_types t ON t.id = l.content_type_id
             WHERE l.slug = ? AND l.is_active = 1 LIMIT 1'
        );
        $stmt->execute([$slug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    public function slugExists(string $slug, ?int $exceptId = null): bool
    {
        $slug = strtolower(trim($slug));
        if ($slug === '') {
            return false;
        }
        if ($exceptId !== null && $exceptId > 0) {
            $stmt = $this->pdo->prepare('SELECT 1 FROM cms_content_lists WHERE slug = ? AND id <> ? LIMIT 1');
            $stmt->execute([$slug, $exceptId]);
        } else {
            $stmt = $this->pdo->prepare('SELECT 1 FROM cms_content_lists WHERE slug = ? LIMIT 1');
            $stmt->execute([$slug]);
        }

        return $stmt->fetchColumn() !== false;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO cms_content_lists
             (name, slug, description, content_type_id, definition_json, is_active, expose_public_page, expose_api)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $data['name'],
            $data['slug'],
            $data['description'],
            $data['content_type_id'],
            $data['definition_json'],
            !empty($data['is_active']) ? 1 : 0,
            !empty($data['expose_public_page']) ? 1 : 0,
            !empty($data['expose_api']) ? 1 : 0,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE cms_content_lists SET
             name = ?, slug = ?, description = ?, content_type_id = ?, definition_json = ?,
             is_active = ?, expose_public_page = ?, expose_api = ?
             WHERE id = ?'
        );
        $stmt->execute([
            $data['name'],
            $data['slug'],
            $data['description'],
            $data['content_type_id'],
            $data['definition_json'],
            !empty($data['is_active']) ? 1 : 0,
            !empty($data['expose_public_page']) ? 1 : 0,
            !empty($data['expose_api']) ? 1 : 0,
            $id,
        ]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM cms_content_lists WHERE id = ?');
        $stmt->execute([$id]);
    }

    public static function definitionFromRow(array $row): ContentListDefinition
    {
        $raw = (string) ($row['definition_json'] ?? '');
        try {
            $decoded = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $decoded = [];
        }

        return ContentListDefinition::fromArray(is_array($decoded) ? $decoded : []);
    }
}
