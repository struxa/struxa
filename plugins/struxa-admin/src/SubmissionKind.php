<?php

declare(strict_types=1);

namespace StruxaAdmin;

final class SubmissionKind
{
    public const PLUGIN = 'plugin';
    public const THEME = 'theme';

    /** @var list<string> */
    public const ALL = [self::PLUGIN, self::THEME];

    public static function isValid(string $kind): bool
    {
        return in_array($kind, self::ALL, true);
    }
}
