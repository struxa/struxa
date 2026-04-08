<?php

declare(strict_types=1);

namespace App\Content;

use App\Media\MediaUrlHelper;
use App\Seo\ExternalLinkPolicy;

/**
 * Prepares structured field rows for public (and admin preview) Twig rendering.
 *
 * @param list<ContentField> $fields
 * @param array<int, string> $valuesByFieldId
 * @return list<array{field_key: string, label: string, field_type: string, value_raw: string, html: string, use_raw: bool}>
 */
final class ContentEntryViewPresenter
{
    public static function buildFieldRows(array $fields, array $valuesByFieldId, MediaUrlHelper $mediaUrls): array
    {
        $rows = [];
        foreach ($fields as $field) {
            $raw = $valuesByFieldId[$field->id] ?? '';
            $rows[] = [
                'field_key' => $field->fieldKey,
                'label' => $field->label,
                'field_type' => $field->fieldType,
                'value_raw' => $raw,
                'html' => self::htmlFor($field, $raw, $mediaUrls),
                'use_raw' => $field->fieldType === 'richtext',
            ];
        }

        return $rows;
    }

    private static function htmlFor(ContentField $field, string $raw, MediaUrlHelper $mediaUrls): string
    {
        $type = $field->fieldType;
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

        return match ($type) {
            'text', 'textarea' => nl2br($e($raw)),
            'richtext' => ExternalLinkPolicy::maybeNofollowExternalAnchorsInHtml(RichtextTabsShortcode::transform($raw)),
            'number' => $e($raw),
            'boolean' => $raw === '1' ? 'Yes' : 'No',
            'select' => $e($raw),
            'date' => $e($raw),
            'url' => $raw !== '' ? self::urlFieldAnchor($raw, $e) : '',
            'image' => self::imageHtml($raw, $mediaUrls, $e),
            default => $e($raw),
        };
    }

    /**
     * @param callable(string): string $e
     */
    private static function urlFieldAnchor(string $raw, callable $e): string
    {
        $rel = ['noopener', 'noreferrer'];
        $host = ExternalLinkPolicy::configuredSiteHost();
        if (ExternalLinkPolicy::isEnabled() && $host !== null && ExternalLinkPolicy::hrefIsExternalHttp($raw, $host)) {
            $rel[] = 'nofollow';
        }

        return '<a href="' . $e($raw) . '" rel="' . $e(implode(' ', array_unique($rel))) . '">' . $e($raw) . '</a>';
    }

    /**
     * @param callable(string): string $e
     */
    private static function imageHtml(string $raw, MediaUrlHelper $mediaUrls, callable $e): string
    {
        if ($raw === '' || !ctype_digit($raw)) {
            return '';
        }
        $path = $mediaUrls->pathForId((int) $raw);
        if ($path === '') {
            return '';
        }

        return '<img src="' . $e($path) . '" alt="" class="content-entry-inline-img" loading="lazy" decoding="async" />';
    }
}
