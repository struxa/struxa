<?php

declare(strict_types=1);

namespace App\Jobs;

/**
 * Human-readable labels for built-in job types (editorial queue UI).
 */
final class JobTypeLabels
{
    /** @var array<string, string> */
    private const LABELS = [
        JobType::MAINTENANCE_PURGE_SCHEDULED => 'Retention purges',
        JobType::SCHEDULE_PUBLISH_DUE => 'Scheduled publish / unpublish',
        JobType::MEDIA_COMPRESS_BATCH => 'Media library optimization',
        JobType::SITEMAP_WARM => 'Sitemap warm cache',
    ];

    public static function label(string $type): string
    {
        $type = trim($type);
        if ($type === '') {
            return 'Unknown';
        }

        return self::LABELS[$type] ?? $type;
    }

    public static function isBuiltin(string $type): bool
    {
        return JobType::isBuiltin($type);
    }
}
