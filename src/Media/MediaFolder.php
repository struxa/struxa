<?php

declare(strict_types=1);

namespace App\Media;

final class MediaFolder
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $slug,
        public readonly ?int $parentId,
        public readonly int $sortOrder,
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
            (string) $row['name'],
            (string) $row['slug'],
            isset($row['parent_id']) && $row['parent_id'] !== null && $row['parent_id'] !== ''
                ? (int) $row['parent_id']
                : null,
            (int) ($row['sort_order'] ?? 0),
            (string) ($row['created_at'] ?? ''),
            (string) ($row['updated_at'] ?? ''),
        );
    }
}
