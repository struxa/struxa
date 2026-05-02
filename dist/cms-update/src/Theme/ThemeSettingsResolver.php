<?php

declare(strict_types=1);

namespace App\Theme;

use App\Settings;

/**
 * Resolves theme.json settings schema defaults with optional cms_settings overrides.
 *
 * Override keys: theme.{slug}.{settingKey}
 */
final class ThemeSettingsResolver
{
    /**
     * @return array<string, string>
     */
    public function resolvedValues(ThemeManifest $manifest): array
    {
        $schema = $manifest->settingsSchema;
        if ($schema === null || $schema === []) {
            return [];
        }

        $out = [];
        foreach ($schema as $key => $def) {
            if (!is_string($key) || $key === '' || !is_array($def)) {
                continue;
            }
            $d = $def['default'] ?? '';
            $default = is_scalar($d) ? (string) $d : '';
            $k = 'theme.' . $manifest->slug . '.' . $key;
            $stored = Settings::get($k, null);
            $out[$key] = $stored !== null && $stored !== '' ? $stored : $default;
        }

        return $out;
    }
}
