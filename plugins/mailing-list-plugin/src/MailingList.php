<?php

declare(strict_types=1);

namespace MailingListPlugin;

final class MailingList
{
    public function __construct(
        public readonly int $id,
        public readonly string $slug,
        public readonly string $name,
        public readonly ?string $description,
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
            isset($row['description']) && $row['description'] !== '' ? (string) $row['description'] : null,
            ((int) ($row['is_active'] ?? 0)) === 1,
            (string) ($row['created_at'] ?? ''),
            (string) ($row['updated_at'] ?? ''),
        );
    }
}
