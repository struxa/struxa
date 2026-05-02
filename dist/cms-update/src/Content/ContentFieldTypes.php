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
    ];

    public static function isValid(string $type): bool
    {
        return in_array($type, self::ALL, true);
    }
}
