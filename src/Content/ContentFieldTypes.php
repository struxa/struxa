<?php

declare(strict_types=1);

namespace App\Content;

final class ContentFieldTypes
{
    /** @var list<string> */
    public const ALL = [
        'text',
        'textarea',
        'richtext',
        'number',
        'boolean',
        'select',
        'image',
        'date',
        'url',
        'entry_refs',
    ];

    public static function isValid(string $type): bool
    {
        return in_array($type, self::ALL, true);
    }
}
