<?php

declare(strict_types=1);

namespace App\Analytics;

final class ShortLink
{
    public function __construct(
        public readonly int $id,
        public readonly string $code,
        public readonly string $destinationUrl,
        public readonly ?string $label,
        public readonly int $clicks,
        public readonly ?int $createdBy,
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
            (string) $row['code'],
            (string) $row['destination_url'],
            isset($row['label']) && $row['label'] !== '' ? (string) $row['label'] : null,
            (int) ($row['clicks'] ?? 0),
            isset($row['created_by']) && $row['created_by'] !== '' && $row['created_by'] !== null
                ? (int) $row['created_by']
                : null,
            (string) ($row['created_at'] ?? ''),
            (string) ($row['updated_at'] ?? ''),
        );
    }
}
