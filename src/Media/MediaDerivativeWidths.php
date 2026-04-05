<?php

declare(strict_types=1);

namespace App\Media;

/**
 * Allowed max-width values for on-demand storefront image derivatives (GET /media-rs/{w}/{id}).
 */
final class MediaDerivativeWidths
{
    /** @var list<int> */
    private const WIDTHS = [320, 480, 640, 768, 960, 1200];

    public static function isAllowed(int $width): bool
    {
        return in_array($width, self::WIDTHS, true);
    }

    /**
     * @return list<int>
     */
    public static function all(): array
    {
        return self::WIDTHS;
    }
}
