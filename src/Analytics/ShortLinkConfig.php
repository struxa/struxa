<?php

declare(strict_types=1);

namespace App\Analytics;

use App\Settings;
use App\Settings\SettingsRepository;
use PDO;

/**
 * Site settings for branded short outbound links.
 */
final class ShortLinkConfig
{
    public const SETTING_ENABLED = 'short_link_enabled';

    public const SETTING_PREFIX = 'short_link_prefix';

    public const SETTING_ROOT_MODE = 'short_link_root_mode';

    public static function enabled(): bool
    {
        return ((string) (Settings::get(self::SETTING_ENABLED, '1') ?? '1')) !== '0';
    }

    /** First URL segment before the code, e.g. "go" → /go/{code}. Empty = no prefix route. */
    public static function prefixSegment(): string
    {
        $raw = strtolower(trim((string) (Settings::get(self::SETTING_PREFIX, 'go') ?? 'go')));
        $raw = trim($raw, '/');
        if ($raw === '') {
            return '';
        }

        return preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $raw) === 1 ? $raw : 'go';
    }

    /** When true, /{code} redirects when the code exists (in addition to prefixed URLs). */
    public static function rootModeEnabled(): bool
    {
        return ((string) (Settings::get(self::SETTING_ROOT_MODE, '0') ?? '0')) === '1';
    }

    public static function save(PDO $pdo, bool $enabled, string $prefixSegment, bool $rootMode): void
    {
        $repo = new SettingsRepository($pdo);
        $prefix = strtolower(trim($prefixSegment));
        $prefix = trim($prefix, '/');
        if ($prefix !== '' && preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $prefix) !== 1) {
            $prefix = 'go';
        }
        $repo->upsert(self::SETTING_ENABLED, $enabled ? '1' : '0', true);
        $repo->upsert(self::SETTING_PREFIX, $prefix, true);
        $repo->upsert(self::SETTING_ROOT_MODE, $rootMode ? '1' : '0', true);
        Settings::reload($pdo);
    }
}
