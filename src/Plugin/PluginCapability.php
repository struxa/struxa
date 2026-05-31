<?php

declare(strict_types=1);

namespace App\Plugin;

/**
 * Declared plugin capabilities (manifest {@code capabilities} array).
 * Runtime enforcement is added in a later phase; activation validates known slugs.
 */
final class PluginCapability
{
    public const DATABASE_READ = 'database.read';
    public const DATABASE_WRITE = 'database.write';
    public const FILESYSTEM_WRITE = 'filesystem.write';
    public const ADMIN_NAV = 'admin.nav';
    public const FRONTEND_RENDER = 'frontend.render';
    public const USER_READ = 'user.read';
    public const SETTINGS_WRITE = 'settings.write';
    public const MEDIA_UPLOAD = 'media.upload';

    /** @var list<string> */
    private const KNOWN = [
        self::DATABASE_READ,
        self::DATABASE_WRITE,
        self::FILESYSTEM_WRITE,
        self::ADMIN_NAV,
        self::FRONTEND_RENDER,
        self::USER_READ,
        self::SETTINGS_WRITE,
        self::MEDIA_UPLOAD,
    ];

    public static function isValid(string $capability): bool
    {
        return in_array($capability, self::KNOWN, true);
    }

    /**
     * @return list<string>
     */
    public static function known(): array
    {
        return self::KNOWN;
    }
}
