<?php

declare(strict_types=1);

namespace App\Publishing;

use App\Settings;
use App\Settings\SettingsRepository;
use PDO;

/**
 * Records when scheduled publish/unpublish (and related housekeeping) last ran.
 */
final class ScheduleRunTracker
{
    public const SETTING_KEY = 'schedule_last_run_at';

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
