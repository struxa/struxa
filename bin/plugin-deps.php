#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Runs `composer install` in each plugins/{slug}/ that has its own composer.json.
 * Use in CI after `composer install` at the repo root, or when a plugin keeps
 * isolated deps. Policy and health checks: **docs/plugins-dependencies.md**,
 * **composer check:plugin-deps** (`bin/plugin-dependency-health.php`).
 *
 * Options:
 *   --dry-run   Print directories only, do not run composer
 *   --no-dev    Pass --no-dev to composer install
 *
 * Composer binary: STRUXA_COMPOSER_PATH in .env (same as admin self-update), or COMPOSER_BIN,
 * then PATH (`composer`), common paths, and $HOME/bin/composer.
 *
 * If HOME/COMPOSER_HOME are unset: STRUXA_WEB_HOME, STRUXA_COMPOSER_HOME, posix pw_dir, or
 * storage/composer-home (writable cache dir for the web user).
 */

$root = dirname(__DIR__);

require $root . '/vendor/autoload.php';

if (is_readable($root . '/.env')) {
    Dotenv\Dotenv::createImmutable($root)->safeLoad();
}

/**
 * Composer refuses to run when both HOME and COMPOSER_HOME are unset (common under PHP-FPM).
 */
function plugin_deps_ensure_composer_environment(string $projectRoot): void
{
    $home = trim((string) ($_ENV['HOME'] ?? getenv('HOME') ?: ''));
    $composerHome = trim((string) ($_ENV['COMPOSER_HOME'] ?? getenv('COMPOSER_HOME') ?: ''));
    if ($home !== '' || $composerHome !== '') {
        return;
    }

    $webHome = trim((string) ($_ENV['STRUXA_WEB_HOME'] ?? getenv('STRUXA_WEB_HOME') ?: ''));
    if ($webHome !== '') {
        putenv('HOME=' . $webHome);
        $_ENV['HOME'] = $webHome;

        return;
    }

    $explicitCh = trim((string) ($_ENV['STRUXA_COMPOSER_HOME'] ?? getenv('STRUXA_COMPOSER_HOME') ?: ''));
    if ($explicitCh !== '') {
        putenv('COMPOSER_HOME=' . $explicitCh);
        $_ENV['COMPOSER_HOME'] = $explicitCh;

        return;
    }

    if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
        $pw = @posix_getpwuid(posix_geteuid());
        if (is_array($pw) && isset($pw['dir']) && is_string($pw['dir']) && $pw['dir'] !== '') {
            putenv('HOME=' . $pw['dir']);
            $_ENV['HOME'] = $pw['dir'];

            return;
        }
    }

    $fallback = $projectRoot . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'composer-home';
    if (!is_dir($fallback)) {
        @mkdir($fallback, 0770, true);
    }
    if (is_dir($fallback) && is_writable($fallback)) {
        putenv('COMPOSER_HOME=' . $fallback);
        $_ENV['COMPOSER_HOME'] = $fallback;
    }
}

/**
 * @return non-empty-string|null
 */
function plugin_deps_resolve_composer_binary(): ?string
{
    $candidates = [];
    foreach (['STRUXA_COMPOSER_PATH', 'COMPOSER_BIN'] as $key) {
        $v = trim((string) ($_ENV[$key] ?? getenv($key) ?: ''));
        if ($v !== '') {
            $candidates[] = $v;
        }
    }

    $home = trim((string) ($_ENV['HOME'] ?? getenv('HOME') ?: ''));
    $candidates = array_merge($candidates, [
        'composer',
        '/usr/local/bin/composer',
        '/usr/bin/composer',
        '/opt/cpanel/composer/bin/composer',
    ]);
    if ($home !== '') {
        $candidates[] = $home . '/bin/composer';
        $candidates[] = $home . '/.config/composer/vendor/bin/composer';
    }

    $seen = [];
    foreach ($candidates as $c) {
        if (isset($seen[$c])) {
            continue;
        }
        $seen[$c] = true;
        $resolved = plugin_deps_resolve_one_composer_candidate($c);
        if ($resolved !== null) {
            return $resolved;
        }
    }

    return null;
}

/**
 * @return non-empty-string|null
 */
function plugin_deps_resolve_one_composer_candidate(string $pathOrName): ?string
{
    if (str_contains($pathOrName, DIRECTORY_SEPARATOR) || str_contains($pathOrName, '/')) {
        return is_file($pathOrName) && is_executable($pathOrName) ? $pathOrName : null;
    }
    $out = [];
    $code = 0;
    @exec('command -v ' . escapeshellarg($pathOrName) . ' 2>/dev/null', $out, $code);
    if ($code !== 0 || !isset($out[0])) {
        return null;
    }
    $p = trim($out[0]);

    return $p !== '' && is_executable($p) ? $p : null;
}

$pluginsDir = $root . '/plugins';

$dry = in_array('--dry-run', $argv, true);
$noDev = in_array('--no-dev', $argv, true);

if (!is_dir($pluginsDir)) {
    fwrite(STDERR, "No plugins directory at {$pluginsDir}\n");
    exit(0);
}

plugin_deps_ensure_composer_environment($root);

$composerBin = plugin_deps_resolve_composer_binary();
if ($composerBin === null) {
    fwrite(STDERR, "composer not found. Set STRUXA_COMPOSER_PATH in .env to the full path (e.g. /home/you/bin/composer), or add Composer to PATH.\n");
    exit(1);
}

$exit = 0;
foreach (scandir($pluginsDir) ?: [] as $name) {
    if ($name === '.' || $name === '..') {
        continue;
    }
    $dir = $pluginsDir . '/' . $name;
    if (!is_dir($dir)) {
        continue;
    }
    $json = $dir . '/composer.json';
    if (!is_file($json)) {
        continue;
    }

    $rel = 'plugins/' . $name;
    echo "[plugin-deps] {$rel}\n";

    if ($dry) {
        continue;
    }

    $args = [$composerBin, 'install', '--no-interaction', '--prefer-dist'];
    if ($noDev) {
        $args[] = '--no-dev';
    }

    $cmd = implode(' ', array_map(escapeshellarg(...), $args));
    $line = 'cd ' . escapeshellarg($dir) . ' && ' . $cmd;
    passthru($line, $code);
    if ($code !== 0) {
        $exit = $code;
    }
}

exit($exit);
