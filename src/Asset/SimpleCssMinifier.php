<?php

declare(strict_types=1);

namespace App\Asset;

/**
 * Whitespace / comment reduction for storefront theme CSS (lightweight, not a full CSS parser).
 * Comments and line comments are stripped only outside of quoted strings; strings are preserved verbatim.
 */
final class SimpleCssMinifier
{
    public static function minify(string $css): string
    {
        $css = self::stripCommentsRespectingStrings($css);
        $css = self::collapseWhitespaceOutsideStrings($css);
        $css = self::trimAroundDelimitersOutsideStrings($css);

        return trim($css);
    }

    private static function stripCommentsRespectingStrings(string $css): string
    {
        $n = strlen($css);
        $out = '';
        $i = 0;
        while ($i < $n) {
            $c = $css[$i];
            if ($c === '"' || $c === "'") {
                $q = $c;
                $out .= $c;
                ++$i;
                while ($i < $n) {
                    $c = $css[$i];
                    if ($c === '\\' && $i + 1 < $n) {
                        $out .= $c . $css[$i + 1];
                        $i += 2;

                        continue;
                    }
                    $out .= $c;
                    if ($c === $q) {
                        ++$i;
                        break;
                    }
                    ++$i;
                }

                continue;
            }
            if ($c === '/' && $i + 1 < $n) {
                $nxt = $css[$i + 1];
                if ($nxt === '*') {
                    $i += 2;
                    while ($i + 1 < $n) {
                        if ($css[$i] === '*' && $css[$i + 1] === '/') {
                            $i += 2;
                            break;
                        }
                        ++$i;
                    }

                    continue;
                }
                if ($nxt === '/') {
                    while ($i < $n && $css[$i] !== "\n" && $css[$i] !== "\r") {
                        ++$i;
                    }

                    continue;
                }
            }
            $out .= $c;
            ++$i;
        }

        return $out;
    }

    private static function collapseWhitespaceOutsideStrings(string $css): string
    {
        $n = strlen($css);
        $out = '';
        $i = 0;
        $pendingSpace = false;
        while ($i < $n) {
            $c = $css[$i];
            if ($c === '"' || $c === "'") {
                if ($pendingSpace) {
                    $out .= ' ';
                    $pendingSpace = false;
                }
                $q = $c;
                $out .= $c;
                ++$i;
                while ($i < $n) {
                    $c = $css[$i];
                    if ($c === '\\' && $i + 1 < $n) {
                        $out .= $c . $css[$i + 1];
                        $i += 2;

                        continue;
                    }
                    $out .= $c;
                    if ($c === $q) {
                        ++$i;
                        break;
                    }
                    ++$i;
                }

                continue;
            }
            if ($c === "\r" || $c === "\n" || $c === "\t" || $c === ' ' || $c === "\f") {
                $pendingSpace = true;
                ++$i;

                continue;
            }
            if ($pendingSpace) {
                $out .= ' ';
                $pendingSpace = false;
            }
            $out .= $c;
            ++$i;
        }

        return $out;
    }

    /**
     * Drop spaces adjacent to `{}:;,>+~` outside quoted strings; keep spaces needed between words (e.g. `border: 1px solid red`).
     */
    private static function trimAroundDelimitersOutsideStrings(string $css): string
    {
        static $delims = null;
        if ($delims === null) {
            $delims = ['{' => true, '}' => true, ':' => true, ';' => true, ',' => true, '>' => true, '+' => true, '~' => true];
        }

        $n = strlen($css);
        $out = '';
        $i = 0;
        while ($i < $n) {
            $c = $css[$i];
            if ($c === '"' || $c === "'") {
                $q = $c;
                $out .= $c;
                ++$i;
                while ($i < $n) {
                    $c = $css[$i];
                    if ($c === '\\' && $i + 1 < $n) {
                        $out .= $c . $css[$i + 1];
                        $i += 2;

                        continue;
                    }
                    $out .= $c;
                    if ($c === $q) {
                        ++$i;
                        break;
                    }
                    ++$i;
                }

                continue;
            }
            if ($c === "\r" || $c === "\n" || $c === "\t" || $c === ' ' || $c === "\f") {
                while ($i < $n && ctype_space($css[$i])) {
                    ++$i;
                }
                if ($i >= $n) {
                    break;
                }
                $next = $css[$i];
                if (isset($delims[$next])) {
                    continue;
                }
                $t = rtrim($out);
                if ($t !== '' && isset($delims[$t[strlen($t) - 1]])) {
                    $out = $t;
                    continue;
                }
                $out = $t . ' ';
                continue;
            }
            if (isset($delims[$c])) {
                $out = rtrim($out) . $c;
                ++$i;

                continue;
            }
            $out .= $c;
            ++$i;
        }

        return $out;
    }
}
