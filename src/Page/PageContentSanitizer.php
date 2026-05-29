<?php

declare(strict_types=1);

namespace App\Page;

use App\Settings\SiteUrlResolver;
use HTMLPurifier;
use HTMLPurifier_Config;

/**
 * Strips unsafe markup before storing page HTML; public templates may render with |raw.
 */
final class PageContentSanitizer
{
    private HTMLPurifier $purifier;

    public function __construct(?string $siteBaseUrl = null)
    {
        $config = HTMLPurifier_Config::createDefault();
        $config->set('Core.Encoding', 'UTF-8');
        $config->set('HTML.Doctype', 'HTML 4.01 Transitional');

        /* HTML 4.01 core + SafeIframe (YouTube / Vimeo embeds). Editor utility classes are class-whitelisted. */
        $allowed = implode(',', [
            'p[style|class]', 'br',
            'strong[style]', 'b[style]', 'em[style]', 'i[style]', 'u[style]', 's[style]',
            'sub', 'sup',
            'span[style|class]',
            'div[style|class]',
            'h1[style|class]', 'h2[style|class]', 'h3[style|class]', 'h4[style|class]', 'h5[style|class]', 'h6[style|class]',
            'ul[style|class]', 'ol[style|class]', 'li[style|class]',
            'blockquote[cite|class|style]',
            'pre[style|class]', 'code[style|class]',
            'a[href|title|target|rel|class]',
            'img[src|alt|title|width|height|class|style]',
            'table[style|class]', 'thead', 'tbody', 'tfoot', 'tr', 'th[style|colspan|rowspan|scope]', 'td[style|colspan|rowspan]',
            'colgroup', 'col[style|span]',
            'hr[style|class]',
            'iframe[src|width|height|frameborder|title|class]',
        ]);
        $config->set('HTML.Allowed', $allowed);
        $config->set('HTML.SafeIframe', true);
        $config->set(
            'URI.SafeIframeRegexp',
            '%^(https?:)?//(www\.youtube\.com/embed/[\w\-]+|www\.youtube-nocookie\.com/embed/[\w\-]+|player\.vimeo\.com/video/\d+)(\\?[\w&=.~\-]*)?%i'
        );
        $config->set('CSS.AllowedProperties', [
            'text-align', 'color', 'background-color', 'margin', 'margin-left', 'margin-right', 'margin-top', 'margin-bottom',
            'padding', 'padding-left', 'padding-right', 'padding-top', 'padding-bottom',
            'border', 'border-width', 'border-style', 'border-color', 'border-collapse', 'border-radius',
            'width', 'max-width', 'height', 'min-height', 'font-size', 'font-weight', 'font-style',
            'text-decoration', 'line-height', 'list-style-type', 'overflow', 'vertical-align',
        ]);
        $codeLangClasses = [
            'language-markup', 'language-html', 'language-xml', 'language-javascript', 'language-js', 'language-css',
            'language-php', 'language-json', 'language-sql', 'language-bash', 'language-shell', 'language-python',
            'language-ts', 'language-typescript', 'language-yaml', 'language-markdown',
        ];
        $config->set('Attr.AllowedClasses', array_merge([
            'cms-intro',
            'cms-callout',
            'cms-caption',
            'mce-pagebreak',
        ], $codeLangClasses));
        $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true]);
        $config->set('Attr.AllowedFrameTargets', ['_blank', '_self']);
        $config->set('HTML.TargetBlank', true);
        /** @see \App\Seo\ExternalLinkPolicy Optional nofollow is applied at render when setting is on. */
        $config->set('HTML.Nofollow', false);
        $config->set('AutoFormat.RemoveEmpty.RemoveNbsp', true);

        $base = $siteBaseUrl !== null ? rtrim($siteBaseUrl, '/') : '';
        if ($base !== '') {
            $config->set('URI.Base', $base);
        }

        $this->purifier = new HTMLPurifier($config);
    }

    public function sanitize(string $html): string
    {
        return $this->purifier->purify($html);
    }

    public static function fromEnv(): self
    {
        $url = SiteUrlResolver::resolve();

        return new self($url !== '' ? $url : null);
    }
}
