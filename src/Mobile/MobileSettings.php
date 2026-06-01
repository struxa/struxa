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
    public const SETTING_CONTENT_SLUGS_JSON = 'mobile_app_content_slugs_json';
    public const SETTING_FEATURES_JSON = 'mobile_app_features_json';

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
     * Explicit content type slugs to expose in the app. Empty stored value = all public types.
     *
     * @return list<string>
     */
    public static function allowedContentTypeSlugs(): array
    {
        $raw = trim((string) (Settings::get(self::SETTING_CONTENT_SLUGS_JSON, '') ?? ''));
        if ($raw === '') {
            return [];
        }

        return self::parseSlugListJson($raw);
    }

    /**
     * Whether a content type slug may be read via the mobile content API / bootstrap list.
     */
    public static function isContentTypeAllowed(string $slug, bool $hasPublicRoute): bool
    {
        if (!$hasPublicRoute) {
            return false;
        }
        $allowed = self::allowedContentTypeSlugs();
        if ($allowed === []) {
            return true;
        }

        return in_array(strtolower(trim($slug)), $allowed, true);
    }

    /**
     * App section toggles merged with site capabilities (commerce, search).
     *
     * @return array{browse: bool, search: bool, shop: bool, account: bool}
     */
    public static function resolvedFeatures(bool $commerceOnSite, bool $searchOnSite): array
    {
        $saved = self::parseFeaturesJson();
        $browse = (bool) ($saved['browse'] ?? true);
        $search = (bool) ($saved['search'] ?? true) && $searchOnSite;
        $shop = (bool) ($saved['shop'] ?? true) && $commerceOnSite;
        $account = (bool) ($saved['account'] ?? true);

        return [
            'browse' => $browse,
            'search' => $search,
            'shop' => $shop,
            'account' => $account,
        ];
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
    public static function defaultTabs(
        bool $commerceEnabled,
        bool $searchEnabled,
        int $publicContentTypeCount,
        ?array $features = null,
    ): array {
        $features ??= [
            'browse' => true,
            'search' => $searchEnabled,
            'shop' => $commerceEnabled,
            'account' => true,
        ];

        $tabs = [
            ['id' => 'home', 'label' => 'Home', 'type' => 'home'],
        ];
        if ($publicContentTypeCount > 0 && ($features['browse'] ?? true)) {
            $tabs[] = ['id' => 'browse', 'label' => 'Browse', 'type' => 'content'];
        }
        if ($searchEnabled && ($features['search'] ?? false)) {
            $tabs[] = ['id' => 'search', 'label' => 'Search', 'type' => 'search'];
        }
        if ($commerceEnabled && ($features['shop'] ?? false)) {
            $tabs[] = ['id' => 'shop', 'label' => 'Shop', 'type' => 'shop'];
        }
        if ($features['account'] ?? true) {
            $tabs[] = ['id' => 'account', 'label' => 'Account', 'type' => 'account'];
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
            $label = mb_substr(trim((string) ($row['label'] ?? '')), 0, 120);
            $type = self::cleanTabToken((string) ($row['type'] ?? ''));
            if ($id === '' || $label === '' || $type === '') {
                continue;
            }
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $tab = ['id' => $id, 'label' => $label, 'type' => $type];
            foreach (self::optionalTabFields($row) as $key => $value) {
                $tab[$key] = $value;
            }
            $out[] = $tab;
            if (count($out) >= 8) {
                break;
            }
        }

        return $out;
    }

    /**
     * @param list<string> $contentSlugs empty = store as empty (meaning all public types on read)
     * @param array{browse?: bool, search?: bool, shop?: bool, account?: bool} $features
     */
    public static function save(
        PDO $pdo,
        bool $enabled,
        string $welcomeTitle,
        string $welcomeMessage,
        bool $includeFooterNav,
        string $tabsJson,
        array $contentSlugs = [],
        array $features = [],
    ): void {
        $welcomeTitle = mb_substr(trim($welcomeTitle), 0, 120);
        $welcomeMessage = mb_substr(trim($welcomeMessage), 0, 500);
        $tabsJson = trim($tabsJson);
        if ($tabsJson !== '') {
            $parsed = self::parseTabsJson($tabsJson);
            $tabsJson = $parsed !== [] ? json_encode($parsed, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) : '';
        }

        $contentJson = self::encodeSlugList($contentSlugs);
        $featuresJson = self::encodeFeatures($features);

        $repo = new SettingsRepository($pdo);
        $repo->upsert(self::SETTING_ENABLED, $enabled ? '1' : '0', true);
        $repo->upsert(self::SETTING_WELCOME_TITLE, $welcomeTitle, true);
        $repo->upsert(self::SETTING_WELCOME_MESSAGE, $welcomeMessage, true);
        $repo->upsert(self::SETTING_INCLUDE_FOOTER_NAV, $includeFooterNav ? '1' : '0', true);
        $repo->upsert(self::SETTING_TABS_JSON, $tabsJson, true);
        $repo->upsert(self::SETTING_CONTENT_SLUGS_JSON, $contentJson, true);
        $repo->upsert(self::SETTING_FEATURES_JSON, $featuresJson, true);
        Settings::reload($pdo);
    }

    /**
     * @return array{browse?: bool, search?: bool, shop?: bool, account?: bool}
     */
    public static function parseFeaturesJson(): array
    {
        $raw = trim((string) (Settings::get(self::SETTING_FEATURES_JSON, '') ?? ''));
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
        foreach (['browse', 'search', 'shop', 'account'] as $key) {
            if (array_key_exists($key, $decoded)) {
                $out[$key] = !empty($decoded[$key]);
            }
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    public static function parseSlugListJson(string $raw): array
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
        foreach ($decoded as $item) {
            if (!is_string($item)) {
                continue;
            }
            $slug = self::cleanTabToken($item);
            if ($slug === '' || isset($seen[$slug])) {
                continue;
            }
            $seen[$slug] = true;
            $out[] = $slug;
        }

        return $out;
    }

    /**
     * @param list<string> $slugs
     */
    public static function encodeSlugList(array $slugs): string
    {
        $clean = [];
        $seen = [];
        foreach ($slugs as $slug) {
            if (!is_string($slug)) {
                continue;
            }
            $token = self::cleanTabToken($slug);
            if ($token === '' || isset($seen[$token])) {
                continue;
            }
            $seen[$token] = true;
            $clean[] = $token;
        }

        if ($clean === []) {
            return '';
        }

        return json_encode(array_values($clean), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param array{browse?: bool, search?: bool, shop?: bool, account?: bool} $features
     */
    public static function encodeFeatures(array $features): string
    {
        $payload = [];
        foreach (['browse', 'search', 'shop', 'account'] as $key) {
            if (array_key_exists($key, $features)) {
                $payload[$key] = !empty($features[$key]);
            }
        }
        if ($payload === []) {
            return '';
        }

        return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return list<string>
     */
    public static function exportableKeys(): array
    {
        return [
            self::SETTING_ENABLED,
            self::SETTING_WELCOME_TITLE,
            self::SETTING_WELCOME_MESSAGE,
            self::SETTING_INCLUDE_FOOTER_NAV,
            self::SETTING_TABS_JSON,
            self::SETTING_CONTENT_SLUGS_JSON,
            self::SETTING_FEATURES_JSON,
        ];
    }

    private static function cleanTabToken(string $value): string
    {
        $value = strtolower(trim($value));
        if ($value === '' || preg_match('/^[a-z0-9_-]{1,32}$/', $value) !== 1) {
            return '';
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, string>
     */
    private static function optionalTabFields(array $row): array
    {
        $out = [];
        $slug = self::cleanTabToken((string) ($row['content_type_slug'] ?? ''));
        if ($slug !== '') {
            $out['content_type_slug'] = $slug;
        }
        $plugin = self::cleanTabToken((string) ($row['plugin_slug'] ?? ''));
        if ($plugin !== '') {
            $out['plugin_slug'] = $plugin;
        }
        $screen = trim((string) ($row['screen'] ?? ''));
        if ($screen !== '' && preg_match('/^[a-z0-9_-]{1,48}$/i', $screen) === 1) {
            $out['screen'] = strtolower($screen);
        }
        $url = trim((string) ($row['url'] ?? ''));
        if ($url !== '' && preg_match('#^https://#i', $url) === 1 && mb_strlen($url) <= 500) {
            $out['url'] = $url;
        }

        return $out;
    }
}
