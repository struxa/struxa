<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Settings;
use App\Settings\SettingsRepository;
use PDO;

/**
 * Records when the CLI job worker last processed jobs.
 */
final class JobRunTracker
{
    public const SETTING_KEY = 'jobs_last_worker_at';

    public static function record(PDO $pdo): void
    {
        (new SettingsRepository($pdo))->upsert(self::SETTING_KEY, gmdate('Y-m-d H:i:s'), true);
        Settings::reload($pdo);
    }

    public static function lastRunAt(): ?string
    {
        $raw = Settings::get(self::SETTING_KEY, '');
        $raw = is_string($raw) ? trim($raw) : '';

        return $raw !== '' ? $raw : null;
    }
}
