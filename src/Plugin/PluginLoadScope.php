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

        if (self::pathIsAdmin($path)) {
            return self::Admin;
        }

        return self::Public;
    }

    /**
     * True when the request targets the CMS admin (not a public content slug named "admin").
     */
    public static function pathIsAdmin(string $path): bool
    {
        if (str_starts_with($path, '/admin')) {
            return true;
        }

        // Front controller: /index.php/admin/... (common on shared hosting and php -S)
        return (bool) preg_match('#/index\.php/admin(?:/|$)#', $path);
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
