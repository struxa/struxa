<?php

declare(strict_types=1);

namespace App\Filter;

use App\Plugin\PluginCapability;

/**
 * Named filter hooks plugins may register against via {@see \App\Plugin\PluginBootContext::addFilter()}.
 */
final class FilterHook
{
    /** Resolved SEO meta (array shape from {@see \App\Seo\SeoMetaFilter::toArray()}). */
    public const SEO_META = 'seo.meta';

    /** Sanitized HTML after core HTMLPurifier (string). */
    public const HTML_SANITIZE = 'html.sanitize';

    /** Public menu items for a location (list of label/href/target/css_class arrays). */
    public const MENU_ITEMS = 'menu.items';

    /** JSON API entry detail payload ({@see \App\Api\PublicContentApi::entryDetail()} shape). */
    public const API_ENTRY_RESPONSE = 'api.entry.response';

    /** JSON API page detail payload ({@see \App\Api\PublicContentApi::pageDetail()} shape). */
    public const API_PAGE_RESPONSE = 'api.page.response';

    /** Inbound REST entry write body before validation ({@see \App\Api\PublicApiEntryPayload::toFormBody()} shape). */
    public const API_ENTRY_REQUEST = 'api.entry.request';

    /** Public page or entry HTML body before render (string). Context: page_id, slug, subject. */
    public const PAGE_RENDER = 'page.render';

    /** Admin dashboard stats array before template ({@see \App\Admin\DashboardStatsCollector::collect()}). */
    public const ADMIN_DASHBOARD = 'admin.dashboard';

    /**
     * Login payload before session is created (array: email, user_id, method, allowed, block_message?).
     * Set allowed=false to block authentication.
     */
    public const USER_LOGIN = 'user.login';

    /** Content entry save POST body before validation (array). Context: content_type_id, entry_id. */
    public const CONTENT_SAVE = 'content.save';

    /** Media upload metadata before storage (array: filename, size, mime, allowed, block_message?). */
    public const MEDIA_UPLOAD = 'media.upload';

    /** Mobile bootstrap JSON payload before response ({@see \App\Mobile\MobileBootstrapService::build()} shape). */
    public const MOBILE_BOOTSTRAP = 'mobile.bootstrap';

    /** @var list<string> */
    private const ALLOWED = [
        self::SEO_META,
        self::HTML_SANITIZE,
        self::MENU_ITEMS,
        self::API_ENTRY_RESPONSE,
        self::API_PAGE_RESPONSE,
        self::API_ENTRY_REQUEST,
        self::PAGE_RENDER,
        self::ADMIN_DASHBOARD,
        self::USER_LOGIN,
        self::CONTENT_SAVE,
        self::MEDIA_UPLOAD,
        self::MOBILE_BOOTSTRAP,
    ];

    /** @var array<string, string> hook => capability */
    private const CAPABILITIES = [
        self::SEO_META => PluginCapability::FRONTEND_RENDER,
        self::HTML_SANITIZE => PluginCapability::FRONTEND_RENDER,
        self::MENU_ITEMS => PluginCapability::FRONTEND_RENDER,
        self::API_ENTRY_RESPONSE => PluginCapability::FRONTEND_RENDER,
        self::API_PAGE_RESPONSE => PluginCapability::FRONTEND_RENDER,
        self::API_ENTRY_REQUEST => PluginCapability::DATABASE_WRITE,
        self::PAGE_RENDER => PluginCapability::FRONTEND_RENDER,
        self::ADMIN_DASHBOARD => PluginCapability::ADMIN_NAV,
        self::USER_LOGIN => PluginCapability::USER_READ,
        self::CONTENT_SAVE => PluginCapability::DATABASE_WRITE,
        self::MEDIA_UPLOAD => PluginCapability::MEDIA_UPLOAD,
        self::MOBILE_BOOTSTRAP => PluginCapability::FRONTEND_RENDER,
    ];

    public static function isValid(string $hook): bool
    {
        return in_array($hook, self::ALLOWED, true);
    }

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return self::ALLOWED;
    }

    public static function requiredCapability(string $hook): ?string
    {
        return self::CAPABILITIES[$hook] ?? null;
    }
}
