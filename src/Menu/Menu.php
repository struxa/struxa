<?php

declare(strict_types=1);

namespace App\Menu;

final class Menu
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $location,
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
            (string) $row['location'],
            (string) ($row['created_at'] ?? ''),
            (string) ($row['updated_at'] ?? ''),
        );
    }
}
