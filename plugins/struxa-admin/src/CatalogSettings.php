<?php

declare(strict_types=1);

namespace StruxaAdmin;

use App\Settings;
use App\Settings\SettingsRepository;
use PDO;

final class CatalogSettings
{
    public const KEY_DIST_ROOT = 'struxa_admin_dist_root';
    public const KEY_ZIP_BASE_URL = 'struxa_admin_zip_base_url';
    public const KEY_GITHUB_TOKEN = 'struxa_admin_github_token';
    public const KEY_SCREENSHOT_BASE_URL = 'struxa_admin_screenshot_base_url';

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $projectRoot,
    ) {
    }

    public function distRoot(): string
    {
        $custom = trim(Settings::get(self::KEY_DIST_ROOT, ''));
        if ($custom !== '') {
            return rtrim($custom, '/\\');
        }

        foreach ([
            $this->projectRoot . '/public/struxa-dist',
            $this->projectRoot . '/struxa-dist',
        ] as $path) {
            if (is_dir($path)) {
                return $path;
            }
        }

        return $this->projectRoot . '/struxa-dist';
    }

    public function zipBaseUrl(): string
    {
        $custom = trim(Settings::get(self::KEY_ZIP_BASE_URL, ''));

        return rtrim($custom !== '' ? $custom : 'https://struxapoint.com/struxa-dist/zips', '/');
    }

    public function screenshotPublicBaseUrl(): string
    {
        $custom = trim(Settings::get(self::KEY_SCREENSHOT_BASE_URL, ''));

        return rtrim($custom !== '' ? $custom : '', '/');
    }

    public function catalogPublicBaseUrl(): string
    {
        $base = $this->screenshotPublicBaseUrl();
        if ($base !== '') {
            return $base;
        }

        return rtrim(\App\Settings\SiteUrlResolver::resolve(), '/');
    }

    public function trackedDownloadUrl(string $kind, string $slug): string
    {
        $kind = SubmissionKind::isValid($kind) ? $kind : SubmissionKind::PLUGIN;
        $slug = strtolower(trim($slug));

        return $this->catalogPublicBaseUrl()
            . '/struxa-catalog/download/'
            . rawurlencode($kind)
            . '/'
            . rawurlencode($slug);
    }

    public function githubToken(): ?string
    {
        $t = trim(Settings::get(self::KEY_GITHUB_TOKEN, ''));

        return $t !== '' ? $t : null;
    }

    /**
     * @param array<string, string> $values
     */
    public function save(array $values): void
    {
        $repo = new SettingsRepository($this->pdo);
        foreach ($values as $key => $value) {
            $repo->upsert($key, $value, true);
        }
        Settings::reload($this->pdo);
    }
}
