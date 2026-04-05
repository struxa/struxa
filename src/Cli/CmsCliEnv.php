<?php

declare(strict_types=1);

namespace App\Cli;

/**
 * Read env for bin/*.php after Dotenv::safeLoad().
 *
 * Some SAPIs omit E from variables_order (so $_ENV stays empty) while vlucas/phpdotenv
 * still uses putenv — use getenv() as a fallback. proc_open children also need DB_* in the
 * passed env array; see {@see \App\Update\CmsSelfUpdater::subprocessEnvironment()}.
 */
final class CmsCliEnv
{
    public static function get(string $key, string $default): string
    {
        if (array_key_exists($key, $_ENV) && is_string($_ENV[$key])) {
            return $_ENV[$key];
        }
        $g = getenv($key);

        return $g !== false ? $g : $default;
    }
}
