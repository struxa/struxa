<?php

declare(strict_types=1);

namespace App\Commerce\Coupon;

use PDO;

final class CouponRepository
{
    private const TABLE = 'cms_commerce_coupons';

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function findById(int $id): ?CommerceCoupon
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ' . self::TABLE . ' WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : CommerceCoupon::fromRow($row);
    }

    public function findByCode(string $code): ?CommerceCoupon
    {
        $code = strtoupper(trim($code));
        if ($code === '') {
            return null;
        }
        $stmt = $this->pdo->prepare('SELECT * FROM ' . self::TABLE . ' WHERE code = ? LIMIT 1');
        $stmt->execute([$code]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : CommerceCoupon::fromRow($row);
    }

    /**
     * @return list<CommerceCoupon>
     */
    public function listAll(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM ' . self::TABLE . ' ORDER BY active DESC, code ASC');
        $out = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $out[] = CommerceCoupon::fromRow($row);
        }

        return $out;
    }

    /**
     * @param array{
     *   code: string,
     *   discount_type: string,
     *   amount: int,
     *   min_subtotal_cents?: int,
     *   max_uses?: ?int,
     *   active?: bool,
     *   expires_at?: ?string,
     * } $data
     */
    public function create(array $data): CommerceCoupon
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO ' . self::TABLE . '
             (code, discount_type, amount, min_subtotal_cents, max_uses, active, expires_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            strtoupper(trim($data['code'])),
            $data['discount_type'] === 'percent' ? 'percent' : 'fixed',
            max(0, (int) $data['amount']),
            max(0, (int) ($data['min_subtotal_cents'] ?? 0)),
            isset($data['max_uses']) && $data['max_uses'] !== null ? max(1, (int) $data['max_uses']) : null,
            !empty($data['active']) ? 1 : 0,
            $data['expires_at'] ?? null,
        ]);
        $id = (int) $this->pdo->lastInsertId();
        $coupon = $this->findById($id);
        if ($coupon === null) {
            throw new \RuntimeException('Failed to load coupon after insert.');
        }

        return $coupon;
    }

    /**
     * @param array{
     *   code?: string,
     *   discount_type?: string,
     *   amount?: int,
     *   min_subtotal_cents?: int,
     *   max_uses?: ?int,
     *   active?: bool,
     *   expires_at?: ?string,
     * } $data
     */
    public function update(int $id, array $data): ?CommerceCoupon
    {
        $existing = $this->findById($id);
        if ($existing === null) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'UPDATE ' . self::TABLE . '
             SET code = ?, discount_type = ?, amount = ?, min_subtotal_cents = ?, max_uses = ?, active = ?, expires_at = ?
             WHERE id = ?'
        );
        $stmt->execute([
            isset($data['code']) ? strtoupper(trim((string) $data['code'])) : $existing->code,
            isset($data['discount_type'])
                ? ($data['discount_type'] === 'percent' ? 'percent' : 'fixed')
                : $existing->discountType,
            isset($data['amount']) ? max(0, (int) $data['amount']) : $existing->amount,
            isset($data['min_subtotal_cents']) ? max(0, (int) $data['min_subtotal_cents']) : $existing->minSubtotalCents,
            array_key_exists('max_uses', $data)
                ? ($data['max_uses'] !== null ? max(1, (int) $data['max_uses']) : null)
                : $existing->maxUses,
            array_key_exists('active', $data) ? (!empty($data['active']) ? 1 : 0) : ($existing->active ? 1 : 0),
            array_key_exists('expires_at', $data) ? $data['expires_at'] : $existing->expiresAt,
            $id,
        ]);

        return $this->findById($id);
    }

    public function incrementUses(int $id): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE ' . self::TABLE . ' SET uses_count = uses_count + 1 WHERE id = ?'
        );
        $stmt->execute([$id]);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM ' . self::TABLE . ' WHERE id = ?');
        $stmt->execute([$id]);

        return $stmt->rowCount() > 0;
    }
}
