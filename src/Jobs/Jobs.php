<?php

declare(strict_types=1);

namespace App\Jobs;

use PDO;
use RuntimeException;

/**
 * Global job queue facade (similar to {@see \App\Filter\Filters}).
 */
final class Jobs
{
    private static ?JobHandlerRegistry $handlers = null;
    private static ?JobQueue $queue = null;

    public static function setHandlerRegistry(JobHandlerRegistry $registry): void
    {
        self::$handlers = $registry;
    }

    public static function setQueue(JobQueue $queue): void
    {
        self::$queue = $queue;
    }

    public static function boot(PDO $pdo, string $projectRoot): void
    {
        $registry = new JobHandlerRegistry();
        $repository = new JobRepository($pdo);
        $queue = new JobQueue($repository);
        $context = new JobHandlerContext($pdo, $projectRoot, $queue);
        BuiltinJobHandlers::register($registry, $context);
        self::$handlers = $registry;
        self::$queue = $queue;
    }

    /**
     * @param callable(Job, JobHandlerContext): array<string, mixed> $handler
     */
    public static function registerHandler(string $type, callable $handler): void
    {
        self::requireHandlers()->register($type, $handler);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function enqueue(
        string $type,
        array $payload = [],
        ?string $dedupeKey = null,
        string $queue = 'default',
        int $delaySeconds = 0,
    ): int {
        return self::requireQueue()->enqueue($type, $payload, $dedupeKey, $queue, $delaySeconds);
    }

    public static function queue(): JobQueue
    {
        return self::requireQueue();
    }

    public static function handlers(): JobHandlerRegistry
    {
        return self::requireHandlers();
    }

    private static function requireHandlers(): JobHandlerRegistry
    {
        if (self::$handlers === null) {
            throw new RuntimeException('Jobs handler registry not booted.');
        }

        return self::$handlers;
    }

    private static function requireQueue(): JobQueue
    {
        if (self::$queue === null) {
            throw new RuntimeException('Jobs queue not booted.');
        }

        return self::$queue;
    }
}
