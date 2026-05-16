<?php

declare(strict_types=1);

namespace MailingListPlugin;

final class Subscriber
{
    public function __construct(
        public readonly int $id,
        public readonly int $listId,
        public readonly string $email,
        public readonly string $status,
        public readonly string $subscribedAt,
        public readonly ?string $unsubscribedAt,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $unsub = $row['unsubscribed_at'] ?? null;

        return new self(
            (int) $row['id'],
            (int) $row['list_id'],
            (string) $row['email'],
            (string) $row['status'],
            (string) ($row['subscribed_at'] ?? ''),
            $unsub !== null && $unsub !== '' ? (string) $unsub : null,
        );
    }
}
