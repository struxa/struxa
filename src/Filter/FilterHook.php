<?php

declare(strict_types=1);

namespace App\Filter;

/**
 * Named filter hooks plugins may register against via {@see PluginBootContext::addFilter()}.
 */
final class FilterHook
{
    /** Resolved SEO meta (array shape from {@see SeoMetaFilter::toArray()}). */
    public const SEO_META = 'seo.meta';

    /** Sanitized HTML after core HTMLPurifier (string). */
    public const HTML_SANITIZE = 'html.sanitize';

    /** Public menu items for a location (list of label/href/target/css_class arrays). */
    public const MENU_ITEMS = 'menu.items';

    /** JSON API entry detail payload ({@see PublicContentApi::entryDetail()} shape). */
    public const API_ENTRY_RESPONSE = 'api.entry.response';

    /** JSON API page detail payload ({@see PublicContentApi::pageDetail()} shape). */
    public const API_PAGE_RESPONSE = 'api.page.response';

    /** Inbound REST entry write body before validation ({@see PublicApiEntryPayload::toFormBody()} shape). */
    public const API_ENTRY_REQUEST = 'api.entry.request';

    /** @var list<string> */
    private const ALLOWED = [
        self::SEO_META,
        self::HTML_SANITIZE,
        self::MENU_ITEMS,
        self::API_ENTRY_RESPONSE,
        self::API_PAGE_RESPONSE,
        self::API_ENTRY_REQUEST,
    ];

    public static function isValid(string $hook): bool
    {
        return in_array($hook, self::ALLOWED, true);
    }
}
