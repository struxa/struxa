<?php

declare(strict_types=1);

namespace App\Jobs;

use RuntimeException;

/**
 * Maps job type strings to handler callables.
 *
 * @phpstan-type JobHandlerResult array{
 *   ok: bool,
 *   message?: string,
 *   retry?: bool,
 *   chain?: list<array{type: string, payload?: array<string, mixed>, dedupe_key?: string|null, delay_seconds?: int}>
 * }
 * @phpstan-type JobHandler callable(Job, JobHandlerContext): JobHandlerResult
 */
final class JobHandlerRegistry
{
    /** @var array<string, JobHandler> */
    private array $handlers = [];

    /**
     * @param JobHandler $handler
     */
    public function register(string $type, callable $handler): void
    {
        $type = trim($type);
        if ($type === '') {
            throw new RuntimeException('Job type cannot be empty.');
        }
        $this->handlers[$type] = $handler;
    }

    public function has(string $type): bool
    {
        return isset($this->handlers[$type]);
    }

    /**
     * @return JobHandlerResult
     */
    public function handle(Job $job, JobHandlerContext $context): array
    {
        $handler = $this->handlers[$job->type] ?? null;
        if ($handler === null) {
            return [
                'ok' => false,
                'message' => 'No handler registered for job type: ' . $job->type,
                'retry' => false,
            ];
        }

        $result = $handler($job, $context);
        if (!is_array($result) || !array_key_exists('ok', $result)) {
            return [
                'ok' => false,
                'message' => 'Job handler returned invalid result for type: ' . $job->type,
                'retry' => false,
            ];
        }

        return $result;
    }
}
