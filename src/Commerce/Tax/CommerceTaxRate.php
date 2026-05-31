<?php

declare(strict_types=1);

namespace App\Commerce\Tax;

final class CommerceTaxRate
{
    public function __construct(
        public readonly int $id,
        public readonly string $countryCode,
        public readonly string $label,
        public readonly int $rateBps,
        public readonly bool $isActive,
        public readonly int $sortOrder,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            strtoupper((string) $row['country_code']),
            (string) ($row['label'] ?? ''),
            max(0, min(10000, (int) ($row['rate_bps'] ?? 0))),
            ((int) ($row['is_active'] ?? 0)) === 1,
            (int) ($row['sort_order'] ?? 0),
        );
    }
}
