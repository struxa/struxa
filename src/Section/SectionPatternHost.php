<?php

declare(strict_types=1);

namespace App\Section;

final class SectionPatternHost
{
    public const PAGE = 'page';
    public const CONTENT_ENTRY = 'content_entry';
    public const BOTH = 'both';

    /** @var list<string> */
    public const ALL = [self::PAGE, self::CONTENT_ENTRY, self::BOTH];

    public static function isValid(string $host): bool
    {
        return in_array($host, self::ALL, true);
    }

    public static function label(string $host): string
    {
        return match ($host) {
            self::PAGE => 'Pages',
            self::CONTENT_ENTRY => 'Content entries',
            self::BOTH => 'Pages and entries',
            default => $host,
        };
    }

    public static function supports(string $patternHost, string $builderHost): bool
    {
        if ($patternHost === self::BOTH) {
            return true;
        }

        return $patternHost === $builderHost;
    }
}
