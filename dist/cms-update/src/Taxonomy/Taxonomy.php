<?php

declare(strict_types=1);

namespace App\Taxonomy;

final class Taxonomy
{
    public function __construct(
        public readonly int $id,
        public readonly int $contentTypeId,
        public readonly string $name,
        public readonly string $slug,
        public readonly ?string $description,
        public readonly string $taxonomyType,
        public readonly bool $isHierarchical,
        public readonly string $createdAt,
        public readonly string $updatedAt,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (int) $row['content_type_id'],
            (string) $row['name'],
            (string) $row['slug'],
            isset($row['description']) && $row['description'] !== '' ? (string) $row['description'] : null,
            (string) $row['taxonomy_type'],
            (bool) ((int) ($row['is_hierarchical'] ?? 0)),
            (string) ($row['created_at'] ?? ''),
            (string) ($row['updated_at'] ?? ''),
        );
    }
}
