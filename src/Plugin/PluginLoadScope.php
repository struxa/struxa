<?php

declare(strict_types=1);

namespace App\Plugin;

/**
 * Request context used to skip plugins that declare {@code load.public/admin/cli} = false.
 */
enum PluginLoadScope: string
{
    case Public = 'public';
    case Admin = 'admin';
    case Cli = 'cli';

    public static function fromWebRequest(string $uri): self
    {
        $path = parse_url($uri, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            $path = $uri;
        }

        return str_starts_with($path, '/admin') ? self::Admin : self::Public;
    }

    public function allows(PluginManifest $manifest): bool
    {
        return match ($this) {
            self::Public => $manifest->loadPublic,
            self::Admin => $manifest->loadAdmin,
            self::Cli => $manifest->loadCli,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Public => 'public',
            self::Admin => 'admin',
            self::Cli => 'CLI',
        };
    }
}
