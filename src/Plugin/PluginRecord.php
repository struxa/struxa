<?php

declare(strict_types=1);

namespace App\Plugin;

final class PluginRecord
{
    public function __construct(
        public readonly int $id,
        public readonly string $slug,
        public readonly string $name,
        public readonly string $version,
        public readonly bool $isActive,
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
            (string) $row['slug'],
            (string) $row['name'],
            (string) $row['version'],
            (bool) ((int) $row['is_active']),
            (string) $row['created_at'],
            (string) $row['updated_at'],
        );
    }
}
