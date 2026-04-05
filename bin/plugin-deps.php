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
 */

$root = dirname(__DIR__);
$pluginsDir = $root . '/plugins';

$dry = in_array('--dry-run', $argv, true);
$noDev = in_array('--no-dev', $argv, true);

if (!is_dir($pluginsDir)) {
    fwrite(STDERR, "No plugins directory at {$pluginsDir}\n");
    exit(0);
}

$composerBin = 'composer';
if (isset($_ENV['COMPOSER_BIN'])) {
    $composerBin = $_ENV['COMPOSER_BIN'];
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
