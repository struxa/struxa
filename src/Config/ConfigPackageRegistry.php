<?php

declare(strict_types=1);

namespace App\Config;

/**
 * Built-in named config packages (Drupal CMI-lite presets).
 */
final class ConfigPackageRegistry
{
    public const PACKAGE_VERSION = '1.0';

    /** @var list<string> */
    public const ALL_SCOPES = [
        'meta',
        'settings',
        'menus',
        'content_types',
        'entries',
        'roles',
        'mobile',
        'commerce',
    ];

    /**
     * @return list<ConfigPackageDefinition>
     */
    public static function builtIn(): array
    {
        return [
            new ConfigPackageDefinition(
                'content-schema',
                'Content schema',
                'Content types, custom fields, taxonomies, and terms.',
                ['content_types'],
            ),
            new ConfigPackageDefinition(
                'navigation',
                'Menus',
                'Header and footer menu trees.',
                ['menus'],
            ),
            new ConfigPackageDefinition(
                'site-settings',
                'Site settings',
                'Site name, SEO defaults, homepage, and related cms_settings keys.',
                ['settings', 'meta'],
            ),
            new ConfigPackageDefinition(
                'mobile-app',
                'Mobile app',
                'Expo/mobile bootstrap settings (tabs, features, welcome copy).',
                ['mobile'],
            ),
            new ConfigPackageDefinition(
                'access-control',
                'Roles & permissions',
                'Custom roles and permission assignments (by permission slug).',
                ['roles'],
            ),
            new ConfigPackageDefinition(
                'commerce-rules',
                'Commerce rules',
                'Shipping zones, tax rates, and coupon definitions (not orders).',
                ['commerce'],
            ),
            new ConfigPackageDefinition(
                'agency-staging',
                'Production → staging',
                'Typical agency sync: schema, menus, settings, roles, mobile, and commerce — no content entries.',
                ['content_types', 'menus', 'settings', 'meta', 'roles', 'mobile', 'commerce'],
            ),
            new ConfigPackageDefinition(
                'full-structure',
                'Full structure (no entries)',
                'Everything except entry rows — closest to a full blueprint without content.',
                ['meta', 'settings', 'menus', 'content_types', 'roles', 'mobile', 'commerce'],
            ),
        ];
    }

    public static function findBuiltIn(string $id): ?ConfigPackageDefinition
    {
        foreach (self::builtIn() as $pkg) {
            if ($pkg->id === $id) {
                return $pkg;
            }
        }

        return null;
    }

    /**
     * @param list<string> $scopes
     * @return list<string>
     */
    public static function normalizeScopes(array $scopes): array
    {
        $allowed = array_flip(self::ALL_SCOPES);
        $out = [];
        foreach ($scopes as $s) {
            $s = trim((string) $s);
            if ($s !== '' && isset($allowed[$s]) && !in_array($s, $out, true)) {
                $out[] = $s;
            }
        }

        return $out;
    }
}
