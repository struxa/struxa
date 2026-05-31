<?php

declare(strict_types=1);

namespace App\Jobs;

final class Job
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public readonly int $id,
        public readonly string $queue,
        public readonly string $type,
        public readonly array $payload,
        public readonly string $status,
        public readonly string $availableAt,
        public readonly int $attempts,
        public readonly int $maxAttempts,
        public readonly ?string $reservedAt,
        public readonly ?string $reservedBy,
        public readonly ?string $resultSummary,
        public readonly ?string $lastError,
        public readonly ?string $dedupeKey,
        public readonly string $createdAt,
        public readonly ?string $completedAt,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $payloadRaw = $row['payload'] ?? null;
        $payload = [];
        if (is_string($payloadRaw) && $payloadRaw !== '') {
            $decoded = json_decode($payloadRaw, true);
            if (is_array($decoded)) {
                $payload = $decoded;
            }
        }

        return new self(
            (int) ($row['id'] ?? 0),
            (string) ($row['queue'] ?? 'default'),
            (string) ($row['type'] ?? ''),
            $payload,
            (string) ($row['status'] ?? JobStatus::PENDING),
            (string) ($row['available_at'] ?? ''),
            (int) ($row['attempts'] ?? 0),
            max(1, (int) ($row['max_attempts'] ?? 3)),
            isset($row['reserved_at']) && $row['reserved_at'] !== null ? (string) $row['reserved_at'] : null,
            isset($row['reserved_by']) && $row['reserved_by'] !== null ? (string) $row['reserved_by'] : null,
            isset($row['result_summary']) && $row['result_summary'] !== null ? (string) $row['result_summary'] : null,
            isset($row['last_error']) && $row['last_error'] !== null ? (string) $row['last_error'] : null,
            isset($row['dedupe_key']) && $row['dedupe_key'] !== null && $row['dedupe_key'] !== ''
                ? (string) $row['dedupe_key']
                : null,
            (string) ($row['created_at'] ?? ''),
            isset($row['completed_at']) && $row['completed_at'] !== null ? (string) $row['completed_at'] : null,
        );
    }
}
