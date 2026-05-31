<?php

declare(strict_types=1);

namespace App\Plugin;

/**
 * Minimal semver constraint matching for plugin dependencies ({@code ^1.2.0}, {@code >=1.0}, exact).
 */
final class PluginSemverConstraint
{
    public static function satisfies(string $installedVersion, string $constraint): bool
    {
        $installedVersion = trim($installedVersion);
        $constraint = trim($constraint);
        if ($constraint === '' || $constraint === '*') {
            return $installedVersion !== '';
        }
        if ($installedVersion === '') {
            return false;
        }

        if (str_starts_with($constraint, '>=')) {
            return version_compare($installedVersion, ltrim(substr($constraint, 2)), '>=');
        }
        if (str_starts_with($constraint, '>')) {
            return version_compare($installedVersion, ltrim(substr($constraint, 1)), '>');
        }
        if (str_starts_with($constraint, '^')) {
            $base = ltrim(substr($constraint, 1));
            if (!preg_match('/^(\d+)\.(\d+)\.(\d+)/', $base, $m)) {
                return version_compare($installedVersion, $base, '>=');
            }
            $major = (int) $m[1];
            if (!preg_match('/^(\d+)\./', $installedVersion, $installedMajor)) {
                return false;
            }
            if ((int) $installedMajor[1] !== $major) {
                return false;
            }

            return version_compare($installedVersion, $base, '>=');
        }

        return version_compare($installedVersion, $constraint, '=');
    }
}
