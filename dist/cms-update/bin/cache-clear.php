#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Clears Struxa file caches (public response + internal data namespaces).
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$envPath = $root . '/.env';
if (is_readable($envPath)) {
    Dotenv\Dotenv::createImmutable($root)->safeLoad();
}

$storage = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'cache';
$manager = new App\Cache\CacheManager($storage);
$themes = new App\Theme\ThemeManager($root);
(new App\Cache\StorefrontCacheInvalidator($manager, $themes))->flushAll();

echo "Cleared storefront file caches under {$storage}\n";
