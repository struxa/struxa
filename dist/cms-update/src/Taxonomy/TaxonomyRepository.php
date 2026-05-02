<?php

declare(strict_types=1);

namespace App\Taxonomy;

use PDO;

final class TaxonomyRepository
{
    private const TABLE = 'cms_taxonomies';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return list<Taxonomy>
     */
    public function forContentTypeOrdered(int $contentTypeId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM ' . self::TABLE . ' WHERE content_type_id = ? ORDER BY name ASC'
        );
        $stmt->execute([$contentTypeId]);
        $out = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $out[] = Taxonomy::fromRow($row);
        }

        return $out;
    }

    public function findById(int $id): ?Taxonomy
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ' . self::TABLE . ' WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : Taxonomy::fromRow($row);
    }

    public function findByContentTypeAndSlug(int $contentTypeId, string $slug): ?Taxonomy
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM ' . self::TABLE . ' WHERE content_type_id = ? AND slug = ? LIMIT 1'
        );
        $stmt->execute([$contentTypeId, $slug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : Taxonomy::fromRow($row);
    }

    public function slugExists(int $contentTypeId, string $slug, ?int $exceptId = null): bool
    {
        if ($exceptId === null) {
            $stmt = $this->pdo->prepare(
                'SELECT 1 FROM ' . self::TABLE . ' WHERE content_type_id = ? AND slug = ? LIMIT 1'
            );
            $stmt->execute([$contentTypeId, $slug]);
        } else {
            $stmt = $this->pdo->prepare(
                'SELECT 1 FROM ' . self::TABLE . ' WHERE content_type_id = ? AND slug = ? AND id != ? LIMIT 1'
            );
            $stmt->execute([$contentTypeId, $slug, $exceptId]);
        }

        return (bool) $stmt->fetchColumn();
    }

    public function belongsToType(int $taxonomyId, int $contentTypeId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM ' . self::TABLE . ' WHERE id = ? AND content_type_id = ? LIMIT 1'
        );
        $stmt->execute([$taxonomyId, $contentTypeId]);

        return (bool) $stmt->fetchColumn();
    }

    /**
     * @return int new id
     */
    public function insert(
        int $contentTypeId,
        string $name,
        string $slug,
        ?string $description,
        string $taxonomyType,
        bool $isHierarchical
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO ' . self::TABLE . '
            (content_type_id, name, slug, description, taxonomy_type, is_hierarchical)
            VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $contentTypeId,
            $name,
            $slug,
            $description,
            $taxonomyType,
            $isHierarchical ? 1 : 0,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function update(
        int $id,
        string $name,
        string $slug,
        ?string $description,
        string $taxonomyType,
        bool $isHierarchical
    ): void {
        $stmt = $this->pdo->prepare(
            'UPDATE ' . self::TABLE . ' SET name = ?, slug = ?, description = ?, taxonomy_type = ?, is_hierarchical = ? WHERE id = ?'
        );
        $stmt->execute([
            $name,
            $slug,
            $description,
            $taxonomyType,
            $isHierarchical ? 1 : 0,
            $id,
        ]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM ' . self::TABLE . ' WHERE id = ?');
        $stmt->execute([$id]);
    }
}
