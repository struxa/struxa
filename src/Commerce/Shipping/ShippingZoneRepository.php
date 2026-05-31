<?php

declare(strict_types=1);

namespace App\Commerce\Shipping;

use PDO;

final class ShippingZoneRepository
{
    private const TABLE = 'cms_commerce_shipping_zones';

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function findById(int $id): ?ShippingZone
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ' . self::TABLE . ' WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : ShippingZone::fromRow($row);
    }

    /**
     * @return list<ShippingZone>
     */
    public function listActiveOrdered(): array
    {
        $stmt = $this->pdo->query(
            'SELECT * FROM ' . self::TABLE . ' WHERE is_active = 1 ORDER BY sort_order ASC, id ASC'
        );
        $out = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $out[] = ShippingZone::fromRow($row);
        }

        return $out;
    }

    /**
     * @return list<ShippingZone>
     */
    public function listAll(): array
    {
        $stmt = $this->pdo->query(
            'SELECT * FROM ' . self::TABLE . ' ORDER BY sort_order ASC, id ASC'
        );
        $out = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $out[] = ShippingZone::fromRow($row);
        }

        return $out;
    }

    /**
     * @param list<string> $countries
     */
    public function create(array $data): ShippingZone
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO ' . self::TABLE . '
             (name, label, price_cents, free_shipping_min_cents, countries_json, sort_order, is_active)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            trim((string) ($data['name'] ?? '')),
            trim((string) ($data['label'] ?? '')),
            max(0, (int) ($data['price_cents'] ?? 0)),
            max(0, (int) ($data['free_shipping_min_cents'] ?? 0)),
            json_encode(self::normalizeCountries($data['countries'] ?? []), JSON_THROW_ON_ERROR),
            (int) ($data['sort_order'] ?? 0),
            !empty($data['is_active']) ? 1 : 0,
        ]);
        $zone = $this->findById((int) $this->pdo->lastInsertId());
        if ($zone === null) {
            throw new \RuntimeException('Failed to load shipping zone after insert.');
        }

        return $zone;
    }

    /**
     * @param list<string> $countries
     */
    public function update(int $id, array $data): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE ' . self::TABLE . '
             SET name = ?, label = ?, price_cents = ?, free_shipping_min_cents = ?,
                 countries_json = ?, sort_order = ?, is_active = ?
             WHERE id = ?'
        );
        $stmt->execute([
            trim((string) ($data['name'] ?? '')),
            trim((string) ($data['label'] ?? '')),
            max(0, (int) ($data['price_cents'] ?? 0)),
            max(0, (int) ($data['free_shipping_min_cents'] ?? 0)),
            json_encode(self::normalizeCountries($data['countries'] ?? []), JSON_THROW_ON_ERROR),
            (int) ($data['sort_order'] ?? 0),
            !empty($data['is_active']) ? 1 : 0,
            $id,
        ]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM ' . self::TABLE . ' WHERE id = ?');
        $stmt->execute([$id]);
    }

    /**
     * @return list<string>
     */
    public function allCountryCodes(): array
    {
        $codes = [];
        foreach ($this->listActiveOrdered() as $zone) {
            foreach ($zone->countries as $code) {
                $codes[$code] = true;
            }
        }

        return array_keys($codes);
    }

    /**
     * @param mixed $countries
     * @return list<string>
     */
    public static function normalizeCountries(mixed $countries): array
    {
        if (is_string($countries)) {
            $countries = preg_split('/[\s,]+/', strtoupper($countries)) ?: [];
        }
        if (!is_array($countries)) {
            return [];
        }
        $out = [];
        foreach ($countries as $code) {
            $c = strtoupper(trim((string) $code));
            if ($c === '*' || $c === 'ROW' || $c === 'REST') {
                continue;
            }
            if (preg_match('/^[A-Z]{2}$/', $c) === 1) {
                $out[] = $c;
            }
        }

        return array_values(array_unique($out));
    }
}
