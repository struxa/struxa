<?php

declare(strict_types=1);

namespace App\Mobile;

use App\Settings;
use App\Settings\SettingsRepository;
use PDO;

/**
 * Mobile app bootstrap settings (cms_settings, autoloaded).
 */
final class MobileSettings
{
    public const SETTING_ENABLED = 'mobile_app_enabled';
    public const SETTING_WELCOME_TITLE = 'mobile_app_welcome_title';
    public const SETTING_WELCOME_MESSAGE = 'mobile_app_welcome_message';
    public const SETTING_INCLUDE_FOOTER_NAV = 'mobile_app_include_footer_nav';
    public const SETTING_TABS_JSON = 'mobile_app_tabs_json';

    public const SCHEMA_VERSION = 1;

    public static function enabled(): bool
    {
        return ((string) (Settings::get(self::SETTING_ENABLED, '1') ?? '1')) === '1';
    }

    public static function welcomeTitle(): string
    {
        return trim((string) (Settings::get(self::SETTING_WELCOME_TITLE, '') ?? ''));
    }

    public static function welcomeMessage(): string
    {
        return trim((string) (Settings::get(self::SETTING_WELCOME_MESSAGE, '') ?? ''));
    }

    public static function includeFooterNav(): bool
    {
        return ((string) (Settings::get(self::SETTING_INCLUDE_FOOTER_NAV, '0') ?? '0')) === '1';
    }

    /**
     * Optional admin override for tab bar. Empty = {@see defaultTabs()}.
     *
     * @return list<array{id: string, label: string, type: string}>
     */
    public static function tabsOverride(): array
    {
        $raw = trim((string) (Settings::get(self::SETTING_TABS_JSON, '') ?? ''));

        return $raw !== '' ? self::parseTabsJson($raw) : [];
    }

    /**
     * @return list<array{id: string, label: string, type: string}>
     */
    public static function defaultTabs(bool $commerceEnabled, bool $searchEnabled, int $publicContentTypeCount): array
    {
        $tabs = [
            ['id' => 'home', 'label' => 'Home', 'type' => 'home'],
        ];
        if ($publicContentTypeCount > 0) {
            $tabs[] = ['id' => 'browse', 'label' => 'Browse', 'type' => 'content'];
        }
        if ($searchEnabled) {
            $tabs[] = ['id' => 'search', 'label' => 'Search', 'type' => 'search'];
        }
        if ($commerceEnabled) {
            $tabs[] = ['id' => 'shop', 'label' => 'Shop', 'type' => 'shop'];
        }

        return $tabs;
    }

    /**
     * @return list<array{id: string, label: string, type: string}>
     */
    public static function parseTabsJson(string $raw): array
    {
        if ($raw === '') {
            return [];
        }
        try {
            $decoded = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }
        if (!is_array($decoded)) {
            return [];
        }

        $out = [];
        $seen = [];
        foreach ($decoded as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = self::cleanTabToken((string) ($row['id'] ?? ''));
            $label = trim((string) ($row['label'] ?? ''));
            $type = self::cleanTabToken((string) ($row['type'] ?? ''));
            if ($id === '' || $label === '' || $type === '') {
                continue;
            }
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $out[] = ['id' => $id, 'label' => $label, 'type' => $type];
            if (count($out) >= 8) {
                break;
            }
        }

        return $out;
    }

    public static function save(
        PDO $pdo,
        bool $enabled,
        string $welcomeTitle,
        string $welcomeMessage,
        bool $includeFooterNav,
        string $tabsJson,
    ): void {
        $welcomeTitle = mb_substr(trim($welcomeTitle), 0, 120);
        $welcomeMessage = mb_substr(trim($welcomeMessage), 0, 500);
        $tabsJson = trim($tabsJson);
        if ($tabsJson !== '') {
            $parsed = self::parseTabsJson($tabsJson);
            $tabsJson = $parsed !== [] ? json_encode($parsed, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) : '';
        }

        $repo = new SettingsRepository($pdo);
        $repo->upsert(self::SETTING_ENABLED, $enabled ? '1' : '0', true);
        $repo->upsert(self::SETTING_WELCOME_TITLE, $welcomeTitle, true);
        $repo->upsert(self::SETTING_WELCOME_MESSAGE, $welcomeMessage, true);
        $repo->upsert(self::SETTING_INCLUDE_FOOTER_NAV, $includeFooterNav ? '1' : '0', true);
        $repo->upsert(self::SETTING_TABS_JSON, $tabsJson, true);
        Settings::reload($pdo);
    }

    private static function cleanTabToken(string $value): string
    {
        $value = strtolower(trim($value));
        if ($value === '' || preg_match('/^[a-z0-9_-]{1,32}$/', $value) !== 1) {
            return '';
        }

        return $value;
    }
}
