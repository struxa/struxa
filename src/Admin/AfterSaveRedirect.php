<?php

declare(strict_types=1);

namespace App\Admin;

use App\Content\ContentType;
use App\Settings;

/**
 * Interprets {@code after_save=view} from admin POST bodies and builds public storefront URLs.
 */
final class AfterSaveRedirect
{
    public static function wantsPublicView(array $body): bool
    {
        return isset($body['after_save']) && (string) $body['after_save'] === 'view';
    }

    public static function entryPublicUrl(string $siteUrl, ContentType $type, string $slug, string $status): ?string
    {
        if ($status !== 'published' || !$type->hasPublicRoute) {
            return null;
        }
        $base = rtrim($siteUrl, '/');

        return $base . '/' . rawurlencode($type->slug) . '/' . rawurlencode($slug);
    }

    public static function pagePublicUrl(string $siteUrl, string $slug, string $status, ?int $pageId): ?string
    {
        if ($status !== 'published') {
            return null;
        }
        $base = rtrim($siteUrl, '/');
        if ($pageId !== null) {
            $homeRaw = Settings::publicHomepagePageIdRaw();
            if ($homeRaw !== '' && ctype_digit($homeRaw) && (int) $homeRaw === $pageId) {
                return $base . '/';
            }
        }

        return $base . '/p/' . rawurlencode($slug);
    }
}
