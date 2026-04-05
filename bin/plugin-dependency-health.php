#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Dev\PluginDependencyHealthCheck;

$root = dirname(__DIR__);

require $root . '/vendor/autoload.php';

if (in_array('--help', $argv, true) || in_array('-h', $argv, true)) {
    fwrite(STDOUT, <<<TXT
Usage: php bin/plugin-dependency-health.php [options]

Read-only checks for plugin Composer trees vs root composer.json and main_class autoload.

Options:
  --active-only     Only plugins with is_active=1 in cms_plugins (needs .env + MySQL).
  --strict          Exit 1 on warnings (e.g. packages not hoisted to root).
  --no-warn-root    Skip warnings for packages only in plugin composer.json.

Exit: 0 = no errors, 1 = errors or (with --strict) warnings.

See docs/plugins-dependencies.md · Run composer plugin-deps to install plugin vendors.

TXT);
    exit(0);
}

$activeOnly = in_array('--active-only', $argv, true);
$strict = in_array('--strict', $argv, true);
$noWarnRoot = in_array('--no-warn-root', $argv, true);

$check = new PluginDependencyHealthCheck($root);
$issues = $check->run($activeOnly, !$noWarnRoot);

$errorCount = 0;
$warnCount = 0;
foreach ($issues as $issue) {
    fwrite(STDOUT, $issue->formatLine() . "\n\n");
    if ($issue->isError()) {
        ++$errorCount;
    } else {
        ++$warnCount;
    }
}

if ($issues === []) {
    fwrite(STDOUT, "Plugin dependency health: OK (no issues).\n");
}

$exit = 0;
if ($errorCount > 0) {
    fwrite(STDERR, "Plugin dependency health: {$errorCount} error(s)" . ($warnCount > 0 ? ", {$warnCount} warning(s)" : '') . ".\n");
    $exit = 1;
} elseif ($strict && $warnCount > 0) {
    fwrite(STDERR, "Plugin dependency health: {$warnCount} warning(s) (--strict → exit 1).\n");
    $exit = 1;
} elseif ($warnCount > 0) {
    fwrite(STDOUT, "Plugin dependency health: {$warnCount} warning(s) (passes; use --strict to fail CI).\n");
}

fwrite(STDOUT, "\nSee docs/plugins-dependencies.md · install plugin vendors: composer plugin-deps\n");

exit($exit);
