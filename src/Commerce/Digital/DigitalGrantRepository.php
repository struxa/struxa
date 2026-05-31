<?php

declare(strict_types=1);

namespace App\Commerce\Digital;

use PDO;

final class DigitalGrantRepository
{
    private const TABLE = 'cms_commerce_digital_grants';

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function findById(int $id): ?DigitalGrant
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ' . self::TABLE . ' WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : DigitalGrant::fromRow($row);
    }

    public function findByToken(string $token): ?DigitalGrant
    {
        $token = trim($token);
        if ($token === '' || strlen($token) !== 64) {
            return null;
        }
        $stmt = $this->pdo->prepare('SELECT * FROM ' . self::TABLE . ' WHERE access_token = ? LIMIT 1');
        $stmt->execute([$token]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : DigitalGrant::fromRow($row);
    }

    /**
     * @return list<DigitalGrant>
     */
    public function forOrder(int $orderId, bool $activeOnly = false): array
    {
        $sql = 'SELECT * FROM ' . self::TABLE . ' WHERE order_id = ?';
        if ($activeOnly) {
            $sql .= ' AND revoked_at IS NULL';
        }
        $sql .= ' ORDER BY id ASC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$orderId]);
        $out = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $out[] = DigitalGrant::fromRow($row);
        }

        return $out;
    }

    public function existsForOrderItem(int $orderItemId): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM ' . self::TABLE . ' WHERE order_item_id = ? LIMIT 1');
        $stmt->execute([$orderItemId]);

        return (bool) $stmt->fetchColumn();
    }

    public function create(
        int $orderId,
        int $orderItemId,
        int $contentEntryId,
        DigitalDeliverySpec $spec,
    ): DigitalGrant {
        $token = $this->generateToken();
        $stmt = $this->pdo->prepare(
            'INSERT INTO ' . self::TABLE . '
             (order_id, order_item_id, content_entry_id, access_token, delivery_type, delivery_payload_json, label)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $orderId,
            $orderItemId,
            $contentEntryId,
            $token,
            $spec->type,
            json_encode($spec->payload, JSON_THROW_ON_ERROR),
            $spec->label,
        ]);
        $grant = $this->findById((int) $this->pdo->lastInsertId());
        if ($grant === null) {
            throw new \RuntimeException('Failed to load digital grant after insert.');
        }

        return $grant;
    }

    public function revoke(int $grantId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE ' . self::TABLE . ' SET revoked_at = COALESCE(revoked_at, CURRENT_TIMESTAMP) WHERE id = ?'
        );
        $stmt->execute([$grantId]);
    }

    public function revokeForOrder(int $orderId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE ' . self::TABLE . ' SET revoked_at = COALESCE(revoked_at, CURRENT_TIMESTAMP) WHERE order_id = ? AND revoked_at IS NULL'
        );
        $stmt->execute([$orderId]);
    }

    public function regenerateToken(int $grantId): ?DigitalGrant
    {
        $grant = $this->findById($grantId);
        if ($grant === null) {
            return null;
        }
        $token = $this->generateToken();
        $stmt = $this->pdo->prepare(
            'UPDATE ' . self::TABLE . ' SET access_token = ?, revoked_at = NULL, download_count = 0, last_download_at = NULL WHERE id = ?'
        );
        $stmt->execute([$token, $grantId]);

        return $this->findById($grantId);
    }

    public function recordDownload(int $grantId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE ' . self::TABLE . ' SET download_count = download_count + 1, last_download_at = CURRENT_TIMESTAMP WHERE id = ?'
        );
        $stmt->execute([$grantId]);
    }

    private function generateToken(): string
    {
        for ($i = 0; $i < 8; $i++) {
            $candidate = bin2hex(random_bytes(32));
            $stmt = $this->pdo->prepare('SELECT 1 FROM ' . self::TABLE . ' WHERE access_token = ? LIMIT 1');
            $stmt->execute([$candidate]);
            if ($stmt->fetchColumn() === false) {
                return $candidate;
            }
        }

        return bin2hex(random_bytes(32));
    }
}
