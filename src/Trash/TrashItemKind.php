<?php

declare(strict_types=1);

namespace App\Trash;

final class TrashItemKind
{
    public const PAGE = 'page';

    public const CONTENT_ENTRY = 'content_entry';

    public const MEDIA = 'media';

    /** @var list<string> */
    private const ALLOWED = [
        self::PAGE,
        self::CONTENT_ENTRY,
        self::MEDIA,
    ];

    public static function isValid(string $kind): bool
    {
        return in_array($kind, self::ALLOWED, true);
    }
}
