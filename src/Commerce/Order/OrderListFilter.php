<?php

declare(strict_types=1);

namespace App\Commerce\Order;

final class OrderListFilter
{
    public function __construct(
        public readonly ?string $status = null,
        public readonly ?string $email = null,
        public readonly ?string $orderNumber = null,
        public readonly ?string $dateFrom = null,
        public readonly ?string $dateTo = null,
        public readonly int $limit = 200,
    ) {
    }

    /**
     * @param array<string, mixed> $query
     */
    public static function fromQueryParams(array $query): self
    {
        $status = isset($query['status']) && is_string($query['status']) ? trim($query['status']) : '';
        $allowed = ['pending', 'paid', 'failed', 'cancelled', 'refunded'];
        $status = in_array($status, $allowed, true) ? $status : null;

        $email = isset($query['email']) && is_string($query['email']) ? trim($query['email']) : '';
        $orderNumber = isset($query['order_number']) && is_string($query['order_number'])
            ? strtoupper(trim($query['order_number'])) : '';
        $dateFrom = isset($query['date_from']) && is_string($query['date_from']) ? trim($query['date_from']) : '';
        $dateTo = isset($query['date_to']) && is_string($query['date_to']) ? trim($query['date_to']) : '';

        return new self(
            $status,
            $email !== '' ? $email : null,
            $orderNumber !== '' ? $orderNumber : null,
            self::normalizeDate($dateFrom),
            self::normalizeDate($dateTo),
            200,
        );
    }

    public function isActive(): bool
    {
        return $this->status !== null
            || $this->email !== null
            || $this->orderNumber !== null
            || $this->dateFrom !== null
            || $this->dateTo !== null;
    }

    private static function normalizeDate(string $value): ?string
    {
        if ($value === '') {
            return null;
        }
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $value);

        return $dt !== false ? $dt->format('Y-m-d') : null;
    }
}
