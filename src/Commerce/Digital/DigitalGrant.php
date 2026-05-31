<?php

declare(strict_types=1);

namespace App\Commerce\Digital;

final class DigitalGrant
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public readonly int $id,
        public readonly int $orderId,
        public readonly int $orderItemId,
        public readonly int $contentEntryId,
        public readonly string $accessToken,
        public readonly string $deliveryType,
        public readonly array $payload,
        public readonly string $label,
        public readonly ?string $revokedAt,
        public readonly int $downloadCount,
        public readonly ?string $lastDownloadAt,
        public readonly string $createdAt,
    ) {
    }

    public function isActive(): bool
    {
        return $this->revokedAt === null || $this->revokedAt === '';
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $payload = [];
        $raw = $row['delivery_payload_json'] ?? '{}';
        if (is_string($raw)) {
            try {
                $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    $payload = $decoded;
                }
            } catch (\JsonException) {
                $payload = [];
            }
        }

        return new self(
            (int) $row['id'],
            (int) $row['order_id'],
            (int) $row['order_item_id'],
            (int) $row['content_entry_id'],
            (string) $row['access_token'],
            (string) $row['delivery_type'],
            $payload,
            (string) ($row['label'] ?? 'Download'),
            isset($row['revoked_at']) && $row['revoked_at'] !== null && $row['revoked_at'] !== ''
                ? (string) $row['revoked_at'] : null,
            (int) ($row['download_count'] ?? 0),
            isset($row['last_download_at']) && $row['last_download_at'] !== null && $row['last_download_at'] !== ''
                ? (string) $row['last_download_at'] : null,
            (string) ($row['created_at'] ?? ''),
        );
    }
}
