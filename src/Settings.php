<?php

declare(strict_types=1);

namespace App;

use PDO;
use PDOException;

/**
 * Key/value settings (cms_settings). Loaded once per request for autoload=1 rows.
 */
final class Settings
{
    private static bool $loaded = false;

    /** @var array<string, string> */
    private static array $values = [];

    public static function boot(PDO $pdo): void
    {
        if (self::$loaded) {
            return;
        }

        try {
            $stmt = $pdo->query(
                'SELECT setting_key, setting_value FROM cms_settings WHERE autoload = 1'
            );
            if ($stmt !== false) {
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $k = (string) ($row['setting_key'] ?? '');
                    self::$values[$k] = (string) ($row['setting_value'] ?? '');
                }
            }
        } catch (PDOException) {
            // cms_settings not created yet (migrations not run)
        }

        self::$loaded = true;
    }

    public static function resetForTests(): void
    {
        self::$loaded = false;
        self::$values = [];
    }

    /** Re-read autoloaded rows from the database (e.g. after admin saves settings). */
    public static function reload(PDO $pdo): void
    {
        self::$loaded = false;
        self::$values = [];
        self::boot($pdo);
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        return array_key_exists($key, self::$values) ? self::$values[$key] : $default;
    }

    /**
     * Published CMS page id (string of digits) to serve at GET /, or empty for the theme's page/home.twig.
     * Reads `public_homepage_page_id` first, then legacy `storefront_homepage_page_id`.
     */
    public static function publicHomepagePageIdRaw(): string
    {
        foreach (['public_homepage_page_id', 'storefront_homepage_page_id'] as $key) {
            $v = self::get($key, '') ?? '';
            $v = is_string($v) ? trim($v) : '';
            if ($v !== '') {
                return $v;
            }
        }

        return '';
    }

    /**
     * @return array<string, string>
     */
    public static function allAutoloaded(): array
    {
        return self::$values;
    }
}
