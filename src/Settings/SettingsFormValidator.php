<?php

declare(strict_types=1);

namespace App\Settings;

use App\Media\MediaRepository;

final class SettingsFormValidator
{
    private const MAX_SHORT = 255;
    private const MAX_PATH = 500;
    private const MAX_META_TITLE = 200;
    private const MAX_META_DESC = 500;
    private const MAX_FOOTER = 2000;

    /**
     * @param array<string, mixed> $body
     * @return array{errors: array<string, string>, values: array<string, string>}
     */
    public function validate(array $body, ?MediaRepository $media = null): array
    {
        $errors = [];

        $siteName = $this->str($body, 'site_name');
        if ($siteName === '') {
            $errors['site_name'] = 'Site name is required.';
        } elseif (mb_strlen($siteName) > 120) {
            $errors['site_name'] = 'Site name must be 120 characters or fewer.';
        }

        $siteTagline = $this->str($body, 'site_tagline');
        if (mb_strlen($siteTagline) > self::MAX_SHORT) {
            $errors['site_tagline'] = 'Tagline is too long.';
        }

        $logoPath = $this->sanitizePath($this->str($body, 'logo_path'));
        if ($logoPath !== '' && mb_strlen($logoPath) > self::MAX_PATH) {
            $errors['logo_path'] = 'Logo path is too long.';
        }
        if ($logoPath !== '' && !$this->isSafeWebPath($logoPath)) {
            $errors['logo_path'] = 'Use a site-relative path (e.g. /images/logo.svg).';
        }

        $faviconPath = $this->sanitizePath($this->str($body, 'favicon_path'));
        if ($faviconPath !== '' && mb_strlen($faviconPath) > self::MAX_PATH) {
            $errors['favicon_path'] = 'Favicon path is too long.';
        }
        if ($faviconPath !== '' && !$this->isSafeWebPath($faviconPath)) {
            $errors['favicon_path'] = 'Use a site-relative path (e.g. /favicon.svg).';
        }

        $metaTitle = $this->str($body, 'default_meta_title');
        if (mb_strlen($metaTitle) > self::MAX_META_TITLE) {
            $errors['default_meta_title'] = 'Default meta title is too long.';
        }

        $metaDesc = $this->str($body, 'default_meta_description');
        if (mb_strlen($metaDesc) > self::MAX_META_DESC) {
            $errors['default_meta_description'] = 'Default meta description is too long.';
        }

        $footerText = $this->str($body, 'footer_text');
        if (mb_strlen($footerText) > self::MAX_FOOTER) {
            $errors['footer_text'] = 'Footer text is too long.';
        }

        $contactEmail = $this->str($body, 'contact_email');
        if ($contactEmail !== '' && !filter_var($contactEmail, FILTER_VALIDATE_EMAIL)) {
            $errors['contact_email'] = 'Enter a valid email or leave blank.';
        }

        foreach (['social_facebook' => 'Facebook', 'social_twitter' => 'Twitter', 'social_instagram' => 'Instagram'] as $key => $label) {
            $url = $this->str($body, $key);
            if ($url !== '' && !filter_var($url, FILTER_VALIDATE_URL)) {
                $errors[$key] = "{$label} URL must be empty or a valid URL.";
            }
        }

        $logoMediaId = $this->optionalMediaId($body, 'logo_media_id', 'Logo', $errors, $media);
        $faviconMediaId = $this->optionalMediaId($body, 'favicon_media_id', 'Favicon', $errors, $media);

        $seoSuffix = $this->str($body, 'seo_title_suffix');
        if (mb_strlen($seoSuffix) > 120) {
            $errors['seo_title_suffix'] = 'Title suffix must be 120 characters or fewer.';
        }

        $robotsCustom = $this->str($body, 'robots_txt_custom');
        if (mb_strlen($robotsCustom) > 32000) {
            $errors['robots_txt_custom'] = 'robots.txt body is too long.';
        }

        $seoOgDefault = $this->optionalMediaId($body, 'seo_default_og_image_media_id', 'Default OG image', $errors, $media);
        $seoTwDefault = $this->optionalMediaId($body, 'seo_default_twitter_image_media_id', 'Default Twitter image', $errors, $media);

        $publicHomePageId = $this->str($body, 'public_homepage_page_id');
        if ($publicHomePageId !== '' && (!ctype_digit($publicHomePageId) || (int) $publicHomePageId < 1)) {
            $errors['public_homepage_page_id'] = 'Homepage: choose a published page or leave blank.';
        }

        $regUsername = $this->str($body, 'registration_collect_username');
        $regUsernameOn = $regUsername === '1' ? '1' : '0';

        $googleEnabled = $this->str($body, 'google_sso_enabled');
        $googleEnabledOn = $googleEnabled === '1' ? '1' : '0';

        $googleClientId = $this->str($body, 'google_oauth_client_id');
        if (mb_strlen($googleClientId) > 512) {
            $errors['google_oauth_client_id'] = 'Google Client ID is too long.';
        }

        $googleSecretIn = $this->str($body, 'google_oauth_client_secret');
        if (mb_strlen($googleSecretIn) > 2048) {
            $errors['google_oauth_client_secret'] = 'Google Client secret is too long.';
        }

        $googleRedirect = $this->str($body, 'google_oauth_redirect_uri');
        if ($googleRedirect !== '' && !filter_var($googleRedirect, FILTER_VALIDATE_URL)) {
            $errors['google_oauth_redirect_uri'] = 'Redirect URI must be empty or a valid URL (https://…).';
        }
        if (mb_strlen($googleRedirect) > 2000) {
            $errors['google_oauth_redirect_uri'] = 'Redirect URI is too long.';
        }

        $googleDomains = $this->str($body, 'google_sso_allowed_domains');
        if (mb_strlen($googleDomains) > 2000) {
            $errors['google_sso_allowed_domains'] = 'Allowed domains list is too long.';
        }

        $googleAuto = $this->str($body, 'google_sso_auto_provision');
        $googleAutoOn = $googleAuto === '1' ? '1' : '0';

        $values = [
            'site_name' => $siteName,
            'site_tagline' => $siteTagline,
            'logo_path' => $logoPath,
            'favicon_path' => $faviconPath,
            'logo_media_id' => $logoMediaId,
            'favicon_media_id' => $faviconMediaId,
            'default_meta_title' => $metaTitle,
            'default_meta_description' => $metaDesc,
            'footer_text' => $footerText,
            'contact_email' => $contactEmail,
            'social_facebook' => $this->str($body, 'social_facebook'),
            'social_twitter' => $this->str($body, 'social_twitter'),
            'social_instagram' => $this->str($body, 'social_instagram'),
            'seo_title_suffix' => $seoSuffix,
            'robots_txt_custom' => $robotsCustom,
            'seo_default_og_image_media_id' => $seoOgDefault,
            'seo_default_twitter_image_media_id' => $seoTwDefault,
            'public_homepage_page_id' => $publicHomePageId === '' ? '' : (string) (int) $publicHomePageId,
            'registration_collect_username' => $regUsernameOn,
            'google_sso_enabled' => $googleEnabledOn,
            'google_oauth_client_id' => $googleClientId,
            'google_oauth_client_secret' => $googleSecretIn,
            'google_oauth_redirect_uri' => $googleRedirect,
            'google_sso_allowed_domains' => $googleDomains,
            'google_sso_auto_provision' => $googleAutoOn,
        ];

        return ['errors' => $errors, 'values' => $values];
    }

    private function str(array $body, string $key): string
    {
        $v = $body[$key] ?? '';

        return trim(is_string($v) ? str_replace("\0", '', $v) : '');
    }

    private function sanitizePath(string $path): string
    {
        $path = trim($path);

        return str_replace("\0", '', $path);
    }

    private function isSafeWebPath(string $path): bool
    {
        if ($path === '' || str_contains($path, '..')) {
            return false;
        }
        if ($path[0] !== '/') {
            return false;
        }

        return (bool) preg_match('#^/[a-zA-Z0-9_./\-]+$#', $path);
    }

    private function optionalMediaId(
        array $body,
        string $key,
        string $label,
        array &$errors,
        ?MediaRepository $media
    ): string {
        $raw = $this->str($body, $key);
        if ($raw === '') {
            return '';
        }
        if (!ctype_digit($raw) || (int) $raw < 1) {
            $errors[$key] = "{$label}: invalid media selection.";

            return $raw;
        }
        $id = (int) $raw;
        if ($media !== null) {
            $m = $media->findById($id);
            if ($m === null || !$m->isImage()) {
                $errors[$key] = "{$label}: choose an image from the media library or leave blank.";

                return $raw;
            }
        }

        return (string) $id;
    }
}
