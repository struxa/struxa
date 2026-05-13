<?php

declare(strict_types=1);

namespace App\Analytics;

use App\Settings;
use App\Settings\SettingsRepository;
use PDO;

/**
 * Site settings backing the External link click tracker.
 *
 * Keys (all stored in {@code cms_settings}):
 *  - {@code external_link_tracking_enabled} — "1" / "0" (default "1")
 *  - {@code external_link_tracking_exclude_hosts} — newline-separated host list (suffix-matched)
 *  - {@code external_link_tracking_retention_days} — purge cap; "0" disables auto-purge
 */
final class ExternalLinkTrackingConfig
{
    public const SETTING_ENABLED = 'external_link_tracking_enabled';
    public const SETTING_EXCLUDE_HOSTS = 'external_link_tracking_exclude_hosts';
    public const SETTING_RETENTION_DAYS = 'external_link_tracking_retention_days';

    public static function enabled(): bool
    {
        return ((string) (Settings::get(self::SETTING_ENABLED, '1') ?? '1')) !== '0';
    }

    /**
     * @return list<string> lowercased hostnames (stripping any leading dots / wildcards)
     */
    public static function excludedHosts(): array
    {
        $raw = (string) (Settings::get(self::SETTING_EXCLUDE_HOSTS, '') ?? '');
        if (trim($raw) === '') {
            return [];
        }
        $out = [];
        foreach (preg_split('/\R+/', $raw) ?: [] as $line) {
            $h = strtolower(trim($line));
            $h = ltrim($h, '.*');
            if ($h === '') {
                continue;
            }
            $out[] = $h;
        }

        return array_values(array_unique($out));
    }

    public static function isHostExcluded(string $host): bool
    {
        $host = strtolower(trim($host));
        if ($host === '') {
            return true;
        }
        foreach (self::excludedHosts() as $pattern) {
            if ($host === $pattern) {
                return true;
            }
            if (str_ends_with($host, '.' . $pattern)) {
                return true;
            }
        }

        return false;
    }

    public static function retentionDays(): int
    {
        $raw = (string) (Settings::get(self::SETTING_RETENTION_DAYS, '0') ?? '0');
        $n = (int) $raw;

        return $n > 0 ? $n : 0;
    }

    public static function save(PDO $pdo, bool $enabled, string $excludeHosts, int $retentionDays): void
    {
        $repo = new SettingsRepository($pdo);
        $repo->upsert(self::SETTING_ENABLED, $enabled ? '1' : '0', true);
        $repo->upsert(self::SETTING_EXCLUDE_HOSTS, trim($excludeHosts), true);
        $repo->upsert(self::SETTING_RETENTION_DAYS, (string) max(0, $retentionDays), true);
        Settings::reload($pdo);
    }
}
