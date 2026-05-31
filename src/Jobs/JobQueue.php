<?php

declare(strict_types=1);

namespace App\Jobs;

/**
 * High-level enqueue helpers for built-in and plugin jobs.
 */
final class JobQueue
{
    public function __construct(
        private readonly JobRepository $repository,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function enqueue(
        string $type,
        array $payload = [],
        ?string $dedupeKey = null,
        string $queue = 'default',
        int $delaySeconds = 0,
        int $maxAttempts = 3,
    ): int {
        return $this->repository->enqueue($type, $payload, $dedupeKey, $queue, $delaySeconds, $maxAttempts);
    }

    /**
     * @param list<array{type: string, payload?: array<string, mixed>, dedupe_key?: string|null, delay_seconds?: int}> $jobs
     */
    public function enqueueMany(array $jobs, string $queue = 'default'): void
    {
        foreach ($jobs as $job) {
            $this->enqueue(
                (string) ($job['type'] ?? ''),
                is_array($job['payload'] ?? null) ? $job['payload'] : [],
                isset($job['dedupe_key']) ? (string) $job['dedupe_key'] : null,
                $queue,
                max(0, (int) ($job['delay_seconds'] ?? 0)),
            );
        }
    }

    public function enqueueScheduledPurges(): int
    {
        return $this->enqueue(
            JobType::MAINTENANCE_PURGE_SCHEDULED,
            [],
            'maintenance.purge_scheduled',
        );
    }

    public function enqueuePublishDue(): int
    {
        return $this->enqueue(
            JobType::SCHEDULE_PUBLISH_DUE,
            [],
            'schedule.publish_due',
        );
    }

    public function enqueueMediaCompressBatch(int $afterId = 0, int $limit = 20, bool $chain = true): int
    {
        $dedupe = $chain && $afterId === 0 ? 'media.compress_batch:full' : null;

        return $this->enqueue(
            JobType::MEDIA_COMPRESS_BATCH,
            [
                'after_id' => max(0, $afterId),
                'limit' => max(1, min(50, $limit)),
                'chain' => $chain,
            ],
            $dedupe,
        );
    }

    public function enqueueSitemapWarm(): int
    {
        return $this->enqueue(
            JobType::SITEMAP_WARM,
            [],
            'sitemap.warm',
        );
    }
}
