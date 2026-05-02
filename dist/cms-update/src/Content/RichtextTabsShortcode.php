<?php

declare(strict_types=1);

namespace App\Content;

/**
 * Turns editor shortcodes in trusted HTML into tab UI (same structure/classes as product body tabs).
 *
 * Syntax (case-insensitive tag names):
 *
 *   [tabs]
 *   [tab title="Overview"] … HTML … [/tab]
 *   [tab label="Stack"] … HTML … [/tab]
 *   [/tabs]
 *
 * With a single chunk and no [tab] pairs, the inner HTML is one pane (no tab bar).
 * Optional TinyMCE wrappers like <p>[tabs]</p> are unwrapped first.
 */
final class RichtextTabsShortcode
{
    public static function transform(string $html): string
    {
        if ($html === '' || stripos($html, '[tabs') === false) {
            return $html;
        }

        $html = self::unwrapShortcodeParagraphs($html);
        $out = '';
        $offset = 0;
        $len = strlen($html);

        while ($offset < $len) {
            if (!preg_match('/\[\s*tabs\s*\]/i', $html, $openMatch, PREG_OFFSET_CAPTURE, $offset)) {
                $out .= substr($html, $offset);
                break;
            }

            $openPos = $openMatch[0][1];
            $openTag = $openMatch[0][0];
            $out .= substr($html, $offset, $openPos - $offset);
            $afterOpen = $openPos + strlen($openTag);

            $closePos = self::findMatchingTabsClose($html, $afterOpen);
            if ($closePos === null) {
                $out .= substr($html, $openPos);
                break;
            }

            if (!preg_match('/\[\s*\/\s*tabs\s*\]/i', $html, $closeMatch, PREG_OFFSET_CAPTURE, $closePos)) {
                $out .= substr($html, $openPos);
                break;
            }

            $inner = substr($html, $afterOpen, $closePos - $afterOpen);
            $out .= self::renderTabsBlock($inner);
            $offset = $closePos + strlen($closeMatch[0][0]);
        }

        return $out;
    }

    private static function unwrapShortcodeParagraphs(string $html): string
    {
        $html = preg_replace('/<p>\s*(\[\s*tabs\s*\])\s*<\/p>/iu', '$1', $html) ?? $html;
        $html = preg_replace('/<p>\s*(\[\s*\/\s*tabs\s*\])\s*<\/p>/iu', '$1', $html) ?? $html;
        $html = preg_replace('/<p>\s*(\[\s*tab\b[^\]]*\])\s*<\/p>/iu', '$1', $html) ?? $html;
        $html = preg_replace('/<p>\s*(\[\s*\/\s*tab\s*\])\s*<\/p>/iu', '$1', $html) ?? $html;

        return $html;
    }

    private static function findMatchingTabsClose(string $html, int $from): ?int
    {
        $depth = 1;
        $len = strlen($html);
        $offset = $from;

        while ($offset < $len) {
            $nextOpen = preg_match('/\[\s*tabs\s*\]/i', $html, $mo, PREG_OFFSET_CAPTURE, $offset)
                ? $mo[0][1] : null;
            $nextClose = preg_match('/\[\s*\/\s*tabs\s*\]/i', $html, $mc, PREG_OFFSET_CAPTURE, $offset)
                ? $mc[0][1] : null;

            if ($nextClose === null) {
                return null;
            }

            if ($nextOpen !== null && $nextOpen < $nextClose) {
                ++$depth;
                $offset = $nextOpen + strlen($mo[0][0]);

                continue;
            }

            --$depth;
            if ($depth === 0) {
                return $nextClose;
            }

            $offset = $nextClose + strlen($mc[0][0]);
        }

        return null;
    }

    /**
     * @return list<array{title: string, html: string}>
     */
    private static function parseTabPanes(string $inner): array
    {
        $inner = trim($inner);
        if ($inner === '') {
            return [];
        }

        $panes = [];
        $offset = 0;
        $len = strlen($inner);
        $unnamed = 0;

        while ($offset < $len) {
            if (!preg_match('/\[\s*tab(?:\s+([^]]*?))?\s*\]/i', $inner, $m, PREG_OFFSET_CAPTURE, $offset)) {
                break;
            }

            $openLen = strlen($m[0][0]);
            $openEnd = $m[0][1] + $openLen;
            $attrs = trim((string) ($m[1][0] ?? ''));

            if (!preg_match('/\[\s*\/\s*tab\s*\]/i', $inner, $cm, PREG_OFFSET_CAPTURE, $openEnd)) {
                break;
            }

            $closeStart = $cm[0][1];
            $paneHtml = substr($inner, $openEnd, $closeStart - $openEnd);
            $title = self::parseTabTitle($attrs);
            if ($title === '') {
                ++$unnamed;
                $title = 'Tab ' . $unnamed;
            }

            $panes[] = [
                'title' => $title,
                'html' => trim($paneHtml),
            ];
            $offset = $closeStart + strlen($cm[0][0]);
        }

        if ($panes === [] && $inner !== '') {
            return [['title' => 'Content', 'html' => $inner]];
        }

        return $panes;
    }

    private static function parseTabTitle(string $attrs): string
    {
        if ($attrs === '') {
            return '';
        }
        if (preg_match('/\b(?:title|label)\s*=\s*"([^"]*)"/i', $attrs, $m)) {
            return trim($m[1]);
        }
        if (preg_match("/\b(?:title|label)\s*=\s*'([^']*)'/i", $attrs, $m)) {
            return trim($m[1]);
        }

        return '';
    }

    private static function renderTabsBlock(string $inner): string
    {
        $panes = self::parseTabPanes($inner);
        if ($panes === []) {
            return '';
        }

        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

        if (count($panes) === 1) {
            $html = $panes[0]['html'];

            return '<div class="cms-tabs-shortcode cms-tabs-shortcode--single product-detail-body-tabs__panel blog-post-body cms-prose theme-cms-body">' . $html . '</div>';
        }

        $uid = 'cms-tab-' . bin2hex(random_bytes(4));
        $bar = '<div class="product-detail-body-tabs__bar" role="tablist" aria-label="Content tabs">';
        $panels = '<div class="product-detail-body-tabs__panels">';
        $prose = 'product-detail-body-tabs__panel blog-post-body cms-prose theme-cms-body';

        foreach ($panes as $i => $pane) {
            $tabId = $uid . '-t' . $i;
            $panelId = $uid . '-p' . $i;
            $isFirst = $i === 0;
            $bar .= sprintf(
                '<button type="button" class="product-detail-body-tabs__tab%s" role="tab" id="%s" aria-controls="%s" aria-selected="%s" tabindex="%d">%s</button>',
                $isFirst ? ' is-active' : '',
                $e($tabId),
                $e($panelId),
                $isFirst ? 'true' : 'false',
                $isFirst ? 0 : -1,
                $e($pane['title'])
            );
            $panels .= sprintf(
                '<div class="%s" id="%s" role="tabpanel" aria-labelledby="%s"%s>%s</div>',
                $e($prose),
                $e($panelId),
                $e($tabId),
                $isFirst ? '' : ' hidden',
                $pane['html']
            );
        }

        $bar .= '</div>';
        $panels .= '</div>';

        return '<div class="product-detail-body-tabs cms-tabs-shortcode" data-cms-tabs role="region" aria-label="Content tabs">'
            . $bar . $panels . '</div>';
    }
}
