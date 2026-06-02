#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Staging / production smoke checklist for public storefront routes.
 *
 * Usage:
 *   STRUXA_SMOKE_BASE_URL=https://staging.example.com composer smoke:staging
 *   STRUXA_SMOKE_BASE_URL=http://127.0.0.1:8080 composer smoke:staging
 *
 * Environment:
 *   STRUXA_SMOKE_BASE_URL   Base URL without trailing slash (default http://127.0.0.1:8080)
 *   STRUXA_SMOKE_TIMEOUT    HTTP timeout seconds (default 20)
 *   STRUXA_SMOKE_STRICT     Set to 0 to allow HTTP 404 on optional routes (default 1)
 *   STRUXA_SMOKE_SKIP       Comma-separated paths to skip, e.g. /forum,/kb
 */

$root = dirname(__DIR__);

require $root . '/vendor/autoload.php';

$base = rtrim((string) (getenv('STRUXA_SMOKE_BASE_URL') ?: 'http://127.0.0.1:8080'), '/');
$timeout = max(5, (int) (getenv('STRUXA_SMOKE_TIMEOUT') ?: 20));
$strict = (getenv('STRUXA_SMOKE_STRICT') ?: '1') !== '0';
$skipRaw = trim((string) (getenv('STRUXA_SMOKE_SKIP') ?: ''));
$skip = $skipRaw === '' ? [] : array_map(static fn (string $p): string => '/' . ltrim(trim($p), '/'), explode(',', $skipRaw));

/** @var list<array{path: string, label: string, optional: bool, must_contain: list<string>}> */
$routes = [
    ['path' => '/', 'label' => 'Homepage', 'optional' => false, 'must_contain' => ['<html', '</body>']],
    ['path' => '/kb', 'label' => 'Knowledge base', 'optional' => false, 'must_contain' => ['st-kb']],
    ['path' => '/forum', 'label' => 'Forum', 'optional' => false, 'must_contain' => ['forum']],
    ['path' => '/shop', 'label' => 'Shop', 'optional' => false, 'must_contain' => ['shop', 'commerce', 'product']],
];

$errorNeedles = [
    'Slim Application Error',
    'PHP Fatal error',
    'Fatal error:',
    'Uncaught Exception',
    'PDOException',
];

/**
 * @return array{ok: bool, status: int, body: string, error: string}
 */
$fetch = static function (string $url) use ($timeout): array {
    $ctx = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => $timeout,
            'ignore_errors' => true,
            'header' => "User-Agent: StruxaSmoke/1.0\r\nAccept: text/html\r\n",
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);

    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) {
        return ['ok' => false, 'status' => 0, 'body' => '', 'error' => 'Request failed (connection or timeout).'];
    }

    $status = 0;
    $headers = function_exists('http_get_last_response_headers')
        ? http_get_last_response_headers()
        : ($GLOBALS['http_response_header'] ?? null);
    if (is_array($headers)) {
        foreach ($headers as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', (string) $line, $m) === 1) {
                $status = (int) $m[1];
                break;
            }
        }
    }

    return ['ok' => true, 'status' => $status, 'body' => $body, 'error' => ''];
};

$failures = 0;
$passed = 0;
$skipped = 0;

fwrite(STDOUT, "Struxa staging smoke — {$base}\n");
fwrite(STDOUT, str_repeat('─', 60) . "\n");

foreach ($routes as $route) {
    $path = $route['path'];
    $label = $route['label'];

    if (in_array($path, $skip, true)) {
        fwrite(STDOUT, "SKIP  {$label} ({$path}) — STRUXA_SMOKE_SKIP\n");
        ++$skipped;
        continue;
    }

    $url = $base . $path;
    $res = $fetch($url);
    if (!$res['ok']) {
        fwrite(STDOUT, "FAIL  {$label} ({$path}) — {$res['error']}\n");
        ++$failures;
        continue;
    }

    $status = $res['status'];
    $body = $res['body'];
    $bodyLower = strtolower($body);

    if ($status >= 500) {
        fwrite(STDOUT, "FAIL  {$label} ({$path}) — HTTP {$status}\n");
        ++$failures;
        continue;
    }

    if ($status === 404 && ($route['optional'] || !$strict)) {
        fwrite(STDOUT, "WARN  {$label} ({$path}) — HTTP 404 (allowed)\n");
        ++$passed;
        continue;
    }

    if ($status < 200 || $status >= 400) {
        fwrite(STDOUT, "FAIL  {$label} ({$path}) — HTTP {$status}\n");
        ++$failures;
        continue;
    }

    foreach ($errorNeedles as $needle) {
        if (stripos($body, $needle) !== false) {
            fwrite(STDOUT, "FAIL  {$label} ({$path}) — response contains “{$needle}”\n");
            ++$failures;
            continue 2;
        }
    }

    $markerOk = false;
    foreach ($route['must_contain'] as $marker) {
        if (stripos($bodyLower, strtolower($marker)) !== false) {
            $markerOk = true;
            break;
        }
    }

    if (!$markerOk) {
        fwrite(STDOUT, "FAIL  {$label} ({$path}) — missing expected content marker\n");
        ++$failures;
        continue;
    }

    fwrite(STDOUT, "OK    {$label} ({$path}) — HTTP {$status}\n");
    ++$passed;
}

fwrite(STDOUT, str_repeat('─', 60) . "\n");
fwrite(STDOUT, "Result: {$passed} passed, {$failures} failed, {$skipped} skipped\n");

exit($failures > 0 ? 1 : 0);
