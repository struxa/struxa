<?php

declare(strict_types=1);

namespace App\Jobs;

/**
 * Built-in job types processed by {@see BuiltinJobHandlers}.
 *
 * Plugins may register additional types via {@see Jobs::registerHandler()}.
 */
final class JobType
{
    public const MAINTENANCE_PURGE_SCHEDULED = 'maintenance.purge_scheduled';
    public const SCHEDULE_PUBLISH_DUE = 'schedule.publish_due';
    public const MEDIA_COMPRESS_BATCH = 'media.compress_batch';
    public const SITEMAP_WARM = 'sitemap.warm';

    /** @var list<string> */
    public const BUILTIN = [
        self::MAINTENANCE_PURGE_SCHEDULED,
        self::SCHEDULE_PUBLISH_DUE,
        self::MEDIA_COMPRESS_BATCH,
        self::SITEMAP_WARM,
    ];

    public static function isBuiltin(string $type): bool
    {
        return in_array($type, self::BUILTIN, true);
    }
}
