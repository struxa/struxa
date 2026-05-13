<?php

declare(strict_types=1);

namespace App\Search;

use App\Content\ContentTypeRepository;
use App\Settings;
use App\Settings\SettingsRepository;
use PDO;

/**
 * Storefront search opt-in + scope.
 *
 * Search is off by default. Admins explicitly pick which content types are searchable, so the
 * feature can never accidentally expose unrelated/private content types. Only published entries
 * on a content type that admins enabled are ever returned.
 *
 * Settings (all in {@code cms_settings}):
 *  - {@code content_search_enabled} ("1"/"0", default "0")
 *  - {@code content_search_type_ids} (comma-separated content type IDs)
 *  - {@code content_search_include_fields} ("1"/"0", default "1")
 *  - {@code content_search_per_page} (int, default 10, clamped 5..50)
 */
final class SearchSettings
{
    public const SETTING_ENABLED = 'content_search_enabled';
    public const SETTING_TYPE_IDS = 'content_search_type_ids';
    public const SETTING_INCLUDE_FIELDS = 'content_search_include_fields';
    public const SETTING_PER_PAGE = 'content_search_per_page';

    public const PER_PAGE_MIN = 5;
    public const PER_PAGE_MAX = 50;
    public const PER_PAGE_DEFAULT = 10;

    public static function enabled(): bool
    {
        return ((string) (Settings::get(self::SETTING_ENABLED, '0') ?? '0')) === '1';
    }

    /**
     * Numeric IDs the admin explicitly allowed. Cast + dedup defensively even though we wrote them.
     *
     * @return list<int>
     */
    public static function allowedTypeIds(): array
    {
        $raw = (string) (Settings::get(self::SETTING_TYPE_IDS, '') ?? '');
        if (trim($raw) === '') {
            return [];
        }
        $out = [];
        foreach (explode(',', $raw) as $part) {
            $n = (int) trim($part);
            if ($n > 0) {
                $out[$n] = true;
            }
        }

        return array_keys($out);
    }

    public static function includeFieldValues(): bool
    {
        return ((string) (Settings::get(self::SETTING_INCLUDE_FIELDS, '1') ?? '1')) !== '0';
    }

    public static function perPage(): int
    {
        $raw = (string) (Settings::get(self::SETTING_PER_PAGE, (string) self::PER_PAGE_DEFAULT) ?? (string) self::PER_PAGE_DEFAULT);
        $n = (int) $raw;
        if ($n < self::PER_PAGE_MIN) {
            $n = self::PER_PAGE_DEFAULT;
        }

        return min(self::PER_PAGE_MAX, $n);
    }

    /**
     * After loading allowed type IDs, drop any that no longer exist or aren't publicly routed.
     *
     * @return list<int>
     */
    public static function activeAllowedTypeIds(PDO $pdo): array
    {
        $wanted = self::allowedTypeIds();
        if ($wanted === []) {
            return [];
        }
        $repo = new ContentTypeRepository($pdo);
        $public = $repo->allWithPublicRoute();
        $publicIds = [];
        foreach ($public as $t) {
            $publicIds[$t->id] = true;
        }
        $out = [];
        foreach ($wanted as $id) {
            if (isset($publicIds[$id])) {
                $out[] = $id;
            }
        }

        return $out;
    }

    /**
     * @param list<int> $typeIds
     */
    public static function save(PDO $pdo, bool $enabled, array $typeIds, bool $includeFields, int $perPage): void
    {
        $clean = [];
        foreach ($typeIds as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $clean[$id] = true;
            }
        }
        $ids = array_keys($clean);
        sort($ids, SORT_NUMERIC);

        $perPage = max(self::PER_PAGE_MIN, min(self::PER_PAGE_MAX, $perPage));

        $repo = new SettingsRepository($pdo);
        $repo->upsert(self::SETTING_ENABLED, $enabled ? '1' : '0', true);
        $repo->upsert(self::SETTING_TYPE_IDS, implode(',', $ids), true);
        $repo->upsert(self::SETTING_INCLUDE_FIELDS, $includeFields ? '1' : '0', true);
        $repo->upsert(self::SETTING_PER_PAGE, (string) $perPage, true);
        Settings::reload($pdo);
    }
}
