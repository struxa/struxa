<?php

declare(strict_types=1);

namespace App\Editing;

final class EditSubjectType
{
    public const PAGE = 'page';

    public const CONTENT_ENTRY = 'content_entry';

    /** @var list<string> */
    private const ALLOWED = [
        self::PAGE,
        self::CONTENT_ENTRY,
    ];

    public static function isValid(string $type): bool
    {
        return in_array($type, self::ALLOWED, true);
    }
}
