<?php

declare(strict_types=1);

namespace App\Richtext;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Converts standalone YouTube / X URLs in paragraph markup into embed blocks before sanitization.
 */
final class RichtextOEmbedExpander
{
    public static function expand(string $html): string
    {
        $trim = trim($html);
        if ($trim === '') {
            return $html;
        }
        $lower = strtolower($trim);
        if (!str_contains($lower, 'youtube')
            && !str_contains($lower, 'youtu.be')
            && !str_contains($lower, 'twitter.com')
            && !str_contains($lower, 'x.com')) {
            return $html;
        }

        $doc = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $loaded = $doc->loadHTML(
            '<?xml encoding="UTF-8"><div id="__cms_oembed_root__">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) {
            return $html;
        }

        $root = $doc->getElementById('__cms_oembed_root__');
        if (!$root instanceof DOMElement) {
            return $html;
        }

        $paragraphs = [];
        foreach ($root->getElementsByTagName('p') as $p) {
            if ($p instanceof DOMElement) {
                $paragraphs[] = $p;
            }
        }

        foreach ($paragraphs as $p) {
            $url = self::extractEmbeddableUrl($p);
            if ($url === null) {
                continue;
            }
            $embed = OEmbedRenderer::renderUrl($url);
            if ($embed === null || $embed === '') {
                continue;
            }
            self::replaceElementWithHtml($doc, $p, $embed);
        }

        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $doc->saveHTML($child);
        }

        return $out !== '' ? $out : $html;
    }

    private static function extractEmbeddableUrl(DOMElement $p): ?string
    {
        if ($p->getElementsByTagName('iframe')->length > 0) {
            return null;
        }

        $meaningful = [];
        foreach ($p->childNodes as $node) {
            if ($node->nodeType === XML_TEXT_NODE && trim($node->textContent ?? '') === '') {
                continue;
            }
            if ($node instanceof DOMElement && strtolower($node->nodeName) === 'br') {
                continue;
            }
            $meaningful[] = $node;
        }

        if (count($meaningful) === 1 && $meaningful[0] instanceof DOMElement && strtolower($meaningful[0]->nodeName) === 'a') {
            $href = trim($meaningful[0]->getAttribute('href'));
            if ($href !== '' && OEmbedUrlParser::parse($href) !== null) {
                return $href;
            }
        }

        $text = trim(str_replace("\xc2\xa0", ' ', $p->textContent ?? ''));
        if ($text !== '' && !str_contains($text, "\n") && OEmbedUrlParser::parse($text) !== null) {
            return $text;
        }

        return null;
    }

    private static function replaceElementWithHtml(DOMDocument $doc, DOMElement $target, string $html): void
    {
        $fragDoc = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $fragDoc->loadHTML(
            '<?xml encoding="UTF-8"><div id="__cms_oembed_frag__">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $wrapper = $fragDoc->getElementById('__cms_oembed_frag__');
        if (!$wrapper instanceof DOMElement || $wrapper->firstChild === null) {
            return;
        }

        $parent = $target->parentNode;
        if (!$parent instanceof DOMNode) {
            return;
        }

        $insertBefore = $target->nextSibling;
        while ($wrapper->firstChild !== null) {
            $child = $wrapper->firstChild;
            $imported = $doc->importNode($child, true);
            if ($insertBefore !== null) {
                $parent->insertBefore($imported, $insertBefore);
            } else {
                $parent->appendChild($imported);
            }
            $wrapper->removeChild($child);
        }
        $parent->removeChild($target);
    }
}
