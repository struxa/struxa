<?php

declare(strict_types=1);

namespace App\Content;

final class ContentType
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $slug,
        public readonly ?string $icon,
        public readonly ?string $description,
        public readonly bool $hasPublicRoute,
        public readonly bool $supportsSeo,
        public readonly bool $supportsFeaturedImage,
        public readonly bool $supportsBlockBuilder,
        public readonly bool $commentsDisabled,
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
            isset($row['icon']) && $row['icon'] !== '' ? (string) $row['icon'] : null,
            isset($row['description']) && $row['description'] !== '' ? (string) $row['description'] : null,
            (bool) ((int) ($row['has_public_route'] ?? 0)),
            (bool) ((int) ($row['supports_seo'] ?? 0)),
            (bool) ((int) ($row['supports_featured_image'] ?? 0)),
            (bool) ((int) ($row['supports_block_builder'] ?? 1)),
            (bool) ((int) ($row['comments_disabled'] ?? 0)),
            (string) ($row['created_at'] ?? ''),
            (string) ($row['updated_at'] ?? ''),
        );
    }
}
