<?php

declare(strict_types=1);

namespace App\Commerce\Tax;

use PDO;

final class TaxRateRepository
{
    private const TABLE = 'cms_commerce_tax_rates';

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function findById(int $id): ?CommerceTaxRate
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ' . self::TABLE . ' WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : CommerceTaxRate::fromRow($row);
    }

    public function findByCountry(string $countryCode): ?CommerceTaxRate
    {
        $countryCode = strtoupper(trim($countryCode));
        if ($countryCode === '') {
            return null;
        }
        $stmt = $this->pdo->prepare(
            'SELECT * FROM ' . self::TABLE . ' WHERE country_code = ? AND is_active = 1 LIMIT 1'
        );
        $stmt->execute([$countryCode]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : CommerceTaxRate::fromRow($row);
    }

    /**
     * @return list<CommerceTaxRate>
     */
    public function listAll(): array
    {
        $stmt = $this->pdo->query(
            'SELECT * FROM ' . self::TABLE . ' ORDER BY sort_order ASC, country_code ASC'
        );
        $out = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $out[] = CommerceTaxRate::fromRow($row);
        }

        return $out;
    }

    /**
     * @return list<CommerceTaxRate>
     */
    public function listActive(): array
    {
        $stmt = $this->pdo->query(
            'SELECT * FROM ' . self::TABLE . ' WHERE is_active = 1 ORDER BY sort_order ASC, country_code ASC'
        );
        $out = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $out[] = CommerceTaxRate::fromRow($row);
        }

        return $out;
    }

    public function create(array $data): CommerceTaxRate
    {
        $country = strtoupper(trim((string) ($data['country_code'] ?? '')));
        if (preg_match('/^[A-Z]{2}$/', $country) !== 1) {
            throw new \InvalidArgumentException('Country code must be ISO 3166-1 alpha-2.');
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO ' . self::TABLE . ' (country_code, label, rate_bps, is_active, sort_order)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $country,
            trim((string) ($data['label'] ?? '')),
            max(0, min(10000, (int) ($data['rate_bps'] ?? 0))),
            !empty($data['is_active']) ? 1 : 0,
            (int) ($data['sort_order'] ?? 0),
        ]);
        $rate = $this->findById((int) $this->pdo->lastInsertId());
        if ($rate === null) {
            throw new \RuntimeException('Failed to load tax rate after insert.');
        }

        return $rate;
    }

    public function update(int $id, array $data): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE ' . self::TABLE . '
             SET label = ?, rate_bps = ?, is_active = ?, sort_order = ?
             WHERE id = ?'
        );
        $stmt->execute([
            trim((string) ($data['label'] ?? '')),
            max(0, min(10000, (int) ($data['rate_bps'] ?? 0))),
            !empty($data['is_active']) ? 1 : 0,
            (int) ($data['sort_order'] ?? 0),
            $id,
        ]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM ' . self::TABLE . ' WHERE id = ?');
        $stmt->execute([$id]);
    }
}
