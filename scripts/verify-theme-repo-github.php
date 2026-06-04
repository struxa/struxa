<?php

declare(strict_types=1);

/**
 * CLI: verify GitHub theme repo validation (e.g. struxa/airline-theme).
 * Run on the server: php scripts/verify-theme-repo-github.php [owner/repo]
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$url = $argv[1] ?? 'https://github.com/struxa/airline-theme';
$branch = $argv[2] ?? 'main';

$pluginDir = $root . '/plugins/struxa-admin';
$clientFile = $pluginDir . '/src/GitHubRepoClient.php';
if (!is_file($clientFile)) {
    fwrite(STDERR, "Missing {$clientFile}\n");
    exit(1);
}

spl_autoload_register(static function (string $class) use ($pluginDir): void {
    if (!str_starts_with($class, 'StruxaAdmin\\')) {
        return;
    }
    $rel = str_replace('\\', '/', substr($class, strlen('StruxaAdmin\\')));
    $path = $pluginDir . '/src/' . $rel . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

use StruxaAdmin\GitHubRepoClient;

$client = new GitHubRepoClient(null);
$parsed = $client->parseRepoUrl($url, $branch);
if (!$parsed['ok']) {
    fwrite(STDERR, $parsed['error'] . "\n");
    exit(1);
}

$result = $client->inspectPackage($parsed['owner'], $parsed['repo'], $parsed['branch'], 'theme');
if (!$result['ok']) {
    fwrite(STDERR, "FAIL: {$result['error']}\n");
    if (!str_contains(file_get_contents($clientFile) ?: '', 'array_is_list')) {
        fwrite(STDERR, "Hint: GitHubRepoClient.php is outdated (needs array_is_list directory check from struxa-admin 1.0.31+).\n");
    }
    exit(1);
}

echo "OK: {$parsed['owner']}/{$parsed['repo']} @ {$result['branch']}\n";
echo 'slug: ' . ($result['manifest']['slug'] ?? '?') . "\n";
echo 'package_root: ' . ($result['package_root'] !== '' ? $result['package_root'] : '(repo root)') . "\n";
