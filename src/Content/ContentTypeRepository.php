<?php

declare(strict_types=1);

namespace App\Content;

use PDO;

final class ContentTypeRepository
{
    private const TABLE = 'cms_content_types';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return list<ContentType>
     */
    public function allOrdered(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM ' . self::TABLE . ' ORDER BY name ASC');
        $out = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $out[] = ContentType::fromRow($row);
        }

        return $out;
    }

    public function findById(int $id): ?ContentType
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ' . self::TABLE . ' WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : ContentType::fromRow($row);
    }

    public function findBySlug(string $slug): ?ContentType
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ' . self::TABLE . ' WHERE slug = ? LIMIT 1');
        $stmt->execute([$slug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : ContentType::fromRow($row);
    }

    public function findBySlugCaseInsensitive(string $slug): ?ContentType
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ' . self::TABLE . ' WHERE LOWER(slug) = LOWER(?) LIMIT 1');
        $stmt->execute([trim($slug)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : ContentType::fromRow($row);
    }

    public function slugExists(string $slug, ?int $exceptId = null): bool
    {
        if ($exceptId === null) {
            $stmt = $this->pdo->prepare('SELECT 1 FROM ' . self::TABLE . ' WHERE slug = ? LIMIT 1');
            $stmt->execute([$slug]);
        } else {
            $stmt = $this->pdo->prepare('SELECT 1 FROM ' . self::TABLE . ' WHERE slug = ? AND id != ? LIMIT 1');
            $stmt->execute([$slug, $exceptId]);
        }

        return (bool) $stmt->fetchColumn();
    }

    public function hasEntries(int $typeId): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM cms_content_entries WHERE content_type_id = ? LIMIT 1');
        $stmt->execute([$typeId]);

        return (bool) $stmt->fetchColumn();
    }

    /**
     * @return int new id
     */
    public function insert(
        string $name,
        string $slug,
        ?string $icon,
        ?string $description,
        bool $hasPublicRoute,
        bool $supportsSeo,
        bool $supportsFeaturedImage,
        bool $supportsBlockBuilder = true,
        bool $commentsDisabled = false,
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO ' . self::TABLE . '
            (name, slug, icon, description, has_public_route, supports_seo, supports_featured_image, supports_block_builder, comments_disabled)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $name,
            $slug,
            $icon,
            $description,
            $hasPublicRoute ? 1 : 0,
            $supportsSeo ? 1 : 0,
            $supportsFeaturedImage ? 1 : 0,
            $supportsBlockBuilder ? 1 : 0,
            $commentsDisabled ? 1 : 0,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function update(
        int $id,
        string $name,
        string $slug,
        ?string $icon,
        ?string $description,
        bool $hasPublicRoute,
        bool $supportsSeo,
        bool $supportsFeaturedImage,
        bool $supportsBlockBuilder,
        bool $commentsDisabled = false,
    ): void {
        $stmt = $this->pdo->prepare(
            'UPDATE ' . self::TABLE . ' SET name = ?, slug = ?, icon = ?, description = ?,
             has_public_route = ?, supports_seo = ?, supports_featured_image = ?, supports_block_builder = ?, comments_disabled = ? WHERE id = ?'
        );
        $stmt->execute([
            $name,
            $slug,
            $icon,
            $description,
            $hasPublicRoute ? 1 : 0,
            $supportsSeo ? 1 : 0,
            $supportsFeaturedImage ? 1 : 0,
            $supportsBlockBuilder ? 1 : 0,
            $commentsDisabled ? 1 : 0,
            $id,
        ]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM ' . self::TABLE . ' WHERE id = ?');
        $stmt->execute([$id]);
    }

    /**
     * @return list<ContentType>
     */
    public function allWithPublicRoute(): array
    {
        $stmt = $this->pdo->query(
            'SELECT * FROM ' . self::TABLE . ' WHERE has_public_route = 1 ORDER BY slug ASC'
        );
        $out = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $out[] = ContentType::fromRow($row);
        }

        return $out;
    }
}
