<?php

declare(strict_types=1);

namespace App\Settings;

use PDO;

/**
 * Form-backed site settings + Twig-facing map with defaults for every key.
 */
final class SiteSettingsService
{
    /** @var array<string, string> */
    public const DEFAULTS = [
        'site_name' => 'Your Studio',
        'site_tagline' => '',
        'logo_path' => '',
        'favicon_path' => '/favicon.svg',
        'default_meta_title' => '',
        'default_meta_description' => '',
        'footer_text' => '',
        'contact_email' => '',
        'social_facebook' => '',
        'social_twitter' => '',
        'social_instagram' => '',
        'logo_media_id' => '',
        'favicon_media_id' => '',
        'seo_title_suffix' => '',
        'robots_txt_custom' => '',
        'seo_default_og_image_media_id' => '',
        'seo_default_twitter_image_media_id' => '',
        /** Empty = theme `page/home.twig`; else published `cms_pages.id` served at GET / */
        'public_homepage_page_id' => '',
        /** When "1", public /register shows a username field (stored on phpauth_users.username). */
        'registration_collect_username' => '0',
        /** Google SSO (also requires client id + secret in Admin → Site settings). */
        'google_sso_enabled' => '0',
        'google_oauth_client_id' => '',
        'google_oauth_client_secret' => '',
        /** Empty = {PHPAUTH_SITE_URL}/auth/google/callback */
        'google_oauth_redirect_uri' => '',
        /** Comma-separated email domains (e.g. company.com). Empty = any verified Google email. */
        'google_sso_allowed_domains' => '',
        /** When "1", first Google sign-in creates a PHPAuth account if the email is new. */
        'google_sso_auto_provision' => '0',
    ];

    /** @var list<string> */
    public const MANAGED_KEYS = [
        'site_name',
        'site_tagline',
        'logo_path',
        'favicon_path',
        'logo_media_id',
        'favicon_media_id',
        'default_meta_title',
        'default_meta_description',
        'footer_text',
        'contact_email',
        'social_facebook',
        'social_twitter',
        'social_instagram',
        'seo_title_suffix',
        'robots_txt_custom',
        'seo_default_og_image_media_id',
        'seo_default_twitter_image_media_id',
        'public_homepage_page_id',
        'registration_collect_username',
        'google_sso_enabled',
        'google_oauth_client_id',
        'google_oauth_client_secret',
        'google_oauth_redirect_uri',
        'google_sso_allowed_domains',
        'google_sso_auto_provision',
    ];

    public function __construct(
        private readonly SettingsRepository $repository
    ) {
    }

    /**
     * @return array<string, string>
     */
    public function forTwig(): array
    {
        $loaded = \App\Settings::allAutoloaded();
        $merged = array_merge(self::DEFAULTS, $loaded);
        $merged['public_homepage_page_id'] = \App\Settings::publicHomepagePageIdRaw();

        return $merged;
    }

    /**
     * Values for the admin form (current DB + defaults).
     *
     * @return array<string, string>
     */
    public function forForm(): array
    {
        $base = $this->forTwig();
        $out = [];
        foreach (self::MANAGED_KEYS as $key) {
            if ($key === 'google_oauth_client_secret') {
                $out[$key] = '';

                continue;
            }
            $out[$key] = $base[$key] ?? '';
        }

        return $out;
    }

    /**
     * @param array<string, string> $validated
     */
    public function save(array $validated, PDO $pdo): void
    {
        $this->repository->upsertMany($validated, true);
        \App\Settings::reload($pdo);
    }
}
