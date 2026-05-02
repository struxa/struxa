<?php

declare(strict_types=1);

namespace App\Taxonomy;

final class TaxonomyType
{
    public const CATEGORY = 'category';

    public const TAG = 'tag';

    public const CUSTOM = 'custom';

    /** @var list<string> */
    private const ALL = [self::CATEGORY, self::TAG, self::CUSTOM];

    public static function isValid(string $type): bool
    {
        return in_array($type, self::ALL, true);
    }
}
