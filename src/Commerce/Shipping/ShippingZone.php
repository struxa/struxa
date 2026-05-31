<?php

declare(strict_types=1);

namespace App\Commerce\Shipping;

final class ShippingZone
{
    /**
     * @param list<string> $countries ISO 3166-1 alpha-2; empty = rest-of-world fallback
     */
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $label,
        public readonly int $priceCents,
        public readonly int $freeShippingMinCents,
        public readonly array $countries,
        public readonly int $sortOrder,
        public readonly bool $isActive,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $countries = [];
        $raw = $row['countries_json'] ?? '[]';
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                foreach ($decoded as $code) {
                    $c = strtoupper(trim((string) $code));
                    if (preg_match('/^[A-Z]{2}$/', $c) === 1) {
                        $countries[] = $c;
                    }
                }
            }
        }

        return new self(
            (int) $row['id'],
            (string) $row['name'],
            (string) $row['label'],
            max(0, (int) ($row['price_cents'] ?? 0)),
            max(0, (int) ($row['free_shipping_min_cents'] ?? 0)),
            $countries,
            (int) ($row['sort_order'] ?? 0),
            ((int) ($row['is_active'] ?? 0)) === 1,
        );
    }

    public function isFallback(): bool
    {
        return $this->countries === [];
    }
}
