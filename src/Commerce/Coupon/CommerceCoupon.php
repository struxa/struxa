<?php

declare(strict_types=1);

namespace App\Commerce\Coupon;

final class CommerceCoupon
{
    public function __construct(
        public readonly int $id,
        public readonly string $code,
        public readonly string $discountType,
        public readonly int $amount,
        public readonly int $minSubtotalCents,
        public readonly ?int $maxUses,
        public readonly int $usesCount,
        public readonly bool $active,
        public readonly ?string $expiresAt,
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
            (string) $row['discount_type'],
            (int) $row['amount'],
            (int) $row['min_subtotal_cents'],
            isset($row['max_uses']) && $row['max_uses'] !== null ? (int) $row['max_uses'] : null,
            (int) $row['uses_count'],
            (int) ($row['active'] ?? 0) === 1,
            isset($row['expires_at']) && $row['expires_at'] !== null && $row['expires_at'] !== ''
                ? (string) $row['expires_at'] : null,
            (string) ($row['created_at'] ?? ''),
            (string) ($row['updated_at'] ?? ''),
        );
    }

    public function isExpired(): bool
    {
        if ($this->expiresAt === null) {
            return false;
        }

        return strtotime($this->expiresAt) !== false && strtotime($this->expiresAt) < time();
    }

    public function isUsable(): bool
    {
        if (!$this->active || $this->isExpired()) {
            return false;
        }
        if ($this->maxUses !== null && $this->usesCount >= $this->maxUses) {
            return false;
        }

        return true;
    }

    public function discountForSubtotal(int $subtotalCents): int
    {
        if ($subtotalCents <= 0) {
            return 0;
        }
        if ($subtotalCents < $this->minSubtotalCents) {
            return 0;
        }

        $discount = match ($this->discountType) {
            'percent' => (int) round($subtotalCents * min(100, max(1, $this->amount)) / 100),
            default => $this->amount,
        };

        return min($subtotalCents, max(0, $discount));
    }
}
