<?php

declare(strict_types=1);

namespace App\Seo;

use App\Settings;
use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Optional site setting: add rel="nofollow" to external http(s) links in public HTML and nav.
 */
final class ExternalLinkPolicy
{
    public static function isEnabled(): bool
    {
        return (Settings::get('seo_external_links_nofollow', '0') ?? '0') === '1';
    }

    /**
     * Canonical site base URL (trimmed), same resolution as {@see \App\Page\PageContentSanitizer::fromEnv()}.
     */
    public static function configuredSiteBaseUrl(): string
    {
        $url = $_ENV['SITE_URL'] ?? getenv('SITE_URL');
        $url = is_string($url) ? trim($url) : '';
        if ($url === '') {
            $url = $_ENV['PHPAUTH_SITE_URL'] ?? getenv('PHPAUTH_SITE_URL');
            $url = is_string($url) ? trim($url) : '';
        }

        return rtrim($url, '/');
    }

    public static function configuredSiteHost(): ?string
    {
        $base = self::configuredSiteBaseUrl();
        if ($base === '') {
            return null;
        }
        $host = parse_url($base, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? strtolower($host) : null;
    }

    public static function siteHostFromSiteUrl(string $siteUrl): ?string
    {
        $siteUrl = trim($siteUrl);
        if ($siteUrl === '') {
            return null;
        }
        $host = parse_url($siteUrl, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? strtolower($host) : null;
    }

    public static function httpUrlHost(string $href): ?string
    {
        $href = trim($href);
        if ($href === '' || $href === '#') {
            return null;
        }
        $lower = strtolower($href);
        if (str_starts_with($lower, 'mailto:') || str_starts_with($lower, 'tel:') || str_starts_with($lower, 'javascript:')) {
            return null;
        }
        if (preg_match('#^https?://#i', $href) === 1) {
            $host = parse_url($href, PHP_URL_HOST);

            return is_string($host) && $host !== '' ? strtolower($host) : null;
        }
        if (str_starts_with($href, '//')) {
            $host = parse_url('https:' . $href, PHP_URL_HOST);

            return is_string($host) && $host !== '' ? strtolower($host) : null;
        }

        return null;
    }

    public static function hrefIsExternalHttp(string $href, string $siteHost): bool
    {
        $linkHost = self::httpUrlHost($href);
        if ($linkHost === null) {
            return false;
        }

        return !self::hostsMatch($linkHost, $siteHost);
    }

    /**
     * rel tokens for header/footer menu items (noopener/noreferrer when target is _blank).
     */
    public static function anchorRelForNavLink(string $href, string $target, bool $policyEnabled, ?string $siteHost): string
    {
        $parts = [];
        if ($target === '_blank') {
            $parts[] = 'noopener';
            $parts[] = 'noreferrer';
        }
        if ($policyEnabled && $siteHost !== null && self::hrefIsExternalHttp($href, $siteHost)) {
            $parts[] = 'nofollow';
        }

        return implode(' ', array_unique($parts));
    }

    public static function maybeNofollowExternalAnchorsInHtml(string $html): string
    {
        if ($html === '' || !self::isEnabled()) {
            return $html;
        }
        $siteHost = self::configuredSiteHost();
        if ($siteHost === null) {
            return $html;
        }

        return self::addNofollowToExternalAnchors($html, $siteHost);
    }

    private static function hostsMatch(string $linkHost, string $siteHost): bool
    {
        $a = strtolower($linkHost);
        $b = strtolower($siteHost);
        if ($a === $b) {
            return true;
        }

        return preg_replace('#^www\.#i', '', $a) === preg_replace('#^www\.#i', '', $b);
    }

    private static function addNofollowToExternalAnchors(string $html, string $siteHost): string
    {
        if (stripos($html, '<a') === false) {
            return $html;
        }

        libxml_use_internal_errors(true);
        $doc = new DOMDocument();
        $uid = 'struxa-nf-' . bin2hex(random_bytes(4));
        $wrapped = '<?xml encoding="UTF-8"><div id="' . $uid . '">' . $html . '</div>';
        $loaded = @$doc->loadHTML($wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        if (!$loaded) {
            return $html;
        }

        $xp = new DOMXPath($doc);
        $rootNodes = $xp->query('//*[@id="' . $uid . '"]');
        $root = ($rootNodes !== false && $rootNodes->length > 0) ? $rootNodes->item(0) : null;
        if (!$root instanceof DOMElement) {
            return $html;
        }

        $nodes = $xp->query('.//a[@href]', $root);
        if ($nodes !== false) {
            foreach ($nodes as $node) {
                if (!$node instanceof DOMElement) {
                    continue;
                }
                $href = trim($node->getAttribute('href'));
                if (!self::hrefIsExternalHttp($href, $siteHost)) {
                    continue;
                }
                self::mergeRelNofollow($node);
            }
        }

        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $doc->saveHTML($child);
        }

        return $out;
    }

    private static function mergeRelNofollow(DOMElement $a): void
    {
        $rel = trim($a->getAttribute('rel'));
        $tokens = $rel === '' ? [] : (preg_split('/\s+/', $rel) ?: []);
        $lower = array_map(strtolower(...), $tokens);
        if (in_array('nofollow', $lower, true)) {
            return;
        }
        $tokens[] = 'nofollow';
        $a->setAttribute('rel', implode(' ', $tokens));
    }
}
