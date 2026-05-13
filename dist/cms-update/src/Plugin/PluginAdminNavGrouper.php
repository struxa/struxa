<?php

declare(strict_types=1);

namespace App\Plugin;

/**
 * Splits {@see PluginAdminNavRegistry} items into a flat list (no parent) and nested groups
 * keyed by {@code parent_plugin_slug} from each plugin's {@code plugin.json}.
 */
final class PluginAdminNavGrouper
{
    /**
     * @param array<int, mixed> $items
     *
     * @return array{
     *     flat: list<array<string, mixed>>,
     *     groups: list<array{parent_slug: string, label: string, items: list<array<string, mixed>>}>
     * }
     */
    public static function partition(array $items, PluginScanner $scanner): array
    {
        $flat = [];
        /** @var array<string, list<array<string, mixed>>> $buckets */
        $buckets = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $parent = isset($item['parent_plugin_slug']) && is_string($item['parent_plugin_slug'])
                ? trim($item['parent_plugin_slug'])
                : '';
            $self = isset($item['plugin_slug']) && is_string($item['plugin_slug'])
                ? trim($item['plugin_slug'])
                : '';

            if ($parent === '' || strcasecmp($parent, $self) === 0 || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $parent)) {
                $flat[] = $item;

                continue;
            }

            if (!isset($buckets[$parent])) {
                $buckets[$parent] = [];
            }
            $buckets[$parent][] = $item;
        }

        $groups = [];
        foreach ($buckets as $parentSlug => $children) {
            usort($children, static function (array $a, array $b): int {
                return strcmp((string) ($a['label'] ?? ''), (string) ($b['label'] ?? ''));
            });
            $discovered = $scanner->findBySlug($parentSlug);
            $label = $discovered !== null ? $discovered->manifest->name : self::humanizeSlug($parentSlug);
            $groups[] = [
                'parent_slug' => $parentSlug,
                'label' => $label,
                'items' => $children,
            ];
        }

        usort($groups, static fn (array $a, array $b): int => strcmp((string) $a['label'], (string) $b['label']));

        return ['flat' => $flat, 'groups' => $groups];
    }

    private static function humanizeSlug(string $slug): string
    {
        $s = str_replace('-', ' ', $slug);
        if ($s === '') {
            return $slug;
        }
        if (function_exists('mb_convert_case')) {
            return mb_convert_case($s, MB_CASE_TITLE, 'UTF-8');
        }

        return ucwords($s);
    }
}
