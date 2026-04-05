<?php

declare(strict_types=1);

namespace App\Media;

use PDO;

/**
 * Adds logo_href / favicon_href to settings: media id wins over raw path fallback.
 *
 * @param array<string, string> $settings
 * @return array<string, string>
 */
final class SiteBrandingResolver
{
    public static function apply(PDO $pdo, array $settings): array
    {
        $repo = new MediaRepository($pdo);
        $urls = new MediaUrlHelper($pdo);

        $logo = self::resolveOne($settings['logo_media_id'] ?? '', $settings['logo_path'] ?? '', $repo, $urls);
        $favPath = trim($settings['favicon_path'] ?? '');
        $fav = self::resolveOne($settings['favicon_media_id'] ?? '', $favPath, $repo, $urls);

        $settings['logo_href'] = $logo;
        $settings['favicon_href'] = $fav !== '' ? $fav : ($favPath !== '' ? $favPath : '/favicon.svg');

        return $settings;
    }

    private static function resolveOne(string $mediaIdSetting, string $pathFallback, MediaRepository $repo, MediaUrlHelper $urls): string
    {
        $id = (int) trim($mediaIdSetting);
        if ($id > 0) {
            $m = $repo->findById($id);
            if ($m !== null && $m->isImage()) {
                $p = $urls->pathForMedia($m);

                return $p !== '' ? $p : trim($pathFallback);
            }
        }

        return trim($pathFallback);
    }
}
