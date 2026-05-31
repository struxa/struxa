<?php

declare(strict_types=1);

namespace App\Plugin;

use App\Event\ContentEntryDeletedEvent;
use App\Event\ContentEntrySavedEvent;
use App\Event\MediaUploadedEvent;
use App\Event\PluginBootedEvent;
use App\Event\StorefrontCachesInvalidateEvent;
use App\Event\UserLoggedInEvent;

/**
 * Events plugins may declare in {@code hooks.events} and listen to at boot.
 */
final class PluginKnownEvents
{
    /** @var array<string, class-string> short name => FQCN */
    private const BY_SHORT = [
        'ContentEntrySavedEvent' => ContentEntrySavedEvent::class,
        'ContentEntryDeletedEvent' => ContentEntryDeletedEvent::class,
        'MediaUploadedEvent' => MediaUploadedEvent::class,
        'UserLoggedInEvent' => UserLoggedInEvent::class,
        'StorefrontCachesInvalidateEvent' => StorefrontCachesInvalidateEvent::class,
        'PluginBootedEvent' => PluginBootedEvent::class,
    ];

    public static function isValid(string $name): bool
    {
        $name = trim($name);
        if ($name === '') {
            return false;
        }
        if (isset(self::BY_SHORT[$name])) {
            return true;
        }

        return in_array($name, self::BY_SHORT, true);
    }

    /**
     * @return list<string> short names for docs/UI
     */
    public static function shortNames(): array
    {
        return array_keys(self::BY_SHORT);
    }
}
