<?php

declare(strict_types=1);

/**
 * Router script for PHP’s built-in server so every request hits Slim (clean URLs).
 *
 *   php -S localhost:8080 -t public public/router.php
 *
 * Without this file, deep links such as `/login` or `/p/about` often return 404 from the built-in server.
 */
$uri = urldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/');
if ($uri !== '/' && $uri !== '' && file_exists(__DIR__ . $uri) && !is_dir(__DIR__ . $uri)) {
    return false;
}

require __DIR__ . '/index.php';
