#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Dev\TwigLayoutContractLinter;

$root = dirname(__DIR__);

require $root . '/vendor/autoload.php';

$warnDuplicates = in_array('--warn-duplicates', $argv, true);
$strict = in_array('--strict', $argv, true);

$linter = new TwigLayoutContractLinter($root);
$issues = $linter->lint($warnDuplicates);

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
    fwrite(STDOUT, "Twig layout contract: OK (no issues).\n");
}

$exit = 0;
if ($errorCount > 0) {
    fwrite(STDERR, "Twig layout contract: {$errorCount} error(s)" . ($warnCount > 0 ? ", {$warnCount} warning(s)" : '') . ".\n");
    $exit = 1;
} elseif ($strict && $warnCount > 0) {
    fwrite(STDERR, "Twig layout contract: {$warnCount} warning(s) (--strict → exit 1).\n");
    $exit = 1;
} elseif ($warnCount > 0) {
    fwrite(STDOUT, "Twig layout contract: {$warnCount} warning(s) (not failing; use --strict to fail CI).\n");
}

exit($exit);
