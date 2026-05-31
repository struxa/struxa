<?php

declare(strict_types=1);

namespace App\Content;

use App\Media\MediaUrlHelper;
use App\Seo\ExternalLinkPolicy;
use PDO;

/**
 * Prepares structured field rows for public (and admin preview) Twig rendering.
 *
 * @param list<ContentField> $fields
 * @param array<int, string> $valuesByFieldId
 * @return list<array{field_key: string, label: string, field_type: string, value_raw: string, html: string, use_raw: bool}>
 */
final class ContentEntryViewPresenter
{
    public static function buildFieldRows(
        array $fields,
        array $valuesByFieldId,
        MediaUrlHelper $mediaUrls,
        ?PDO $pdo = null,
        ?string $publicSiteUrl = null,
    ): array {
        $rows = [];
        foreach ($fields as $field) {
            $raw = $valuesByFieldId[$field->id] ?? '';
            $rows[] = [
                'field_key' => $field->fieldKey,
                'label' => $field->label,
                'field_type' => $field->fieldType,
                'value_raw' => $raw,
                'html' => self::htmlFor($field, $raw, $mediaUrls, $pdo, $publicSiteUrl),
                'use_raw' => $field->fieldType === 'richtext',
            ];
        }

        return $rows;
    }

    private static function htmlFor(
        ContentField $field,
        string $raw,
        MediaUrlHelper $mediaUrls,
        ?PDO $pdo,
        ?string $publicSiteUrl,
    ): string {
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
            'entry_refs' => self::entryRefsHtml($raw, $pdo, $publicSiteUrl, $e),
            default => $e($raw),
        };
    }

    /**
     * @param callable(string): string $e
     */
    private static function entryRefsHtml(string $raw, ?PDO $pdo, ?string $publicSiteUrl, callable $e): string
    {
        $ids = ContentEntryReferenceIds::parse($raw);
        if ($ids === []) {
            return '';
        }
        if ($pdo === null) {
            $labels = [];
            foreach ($ids as $id) {
                $labels[] = '#' . $id;
            }

            return '<p class="content-entry-refs-fallback">' . $e(implode(', ', $labels)) . '</p>';
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare(
            'SELECT e.*, t.slug AS type_slug, t.has_public_route
             FROM cms_content_entries e
             INNER JOIN cms_content_types t ON t.id = e.content_type_id
             WHERE e.id IN (' . $placeholders . ') AND e.deleted_at IS NULL'
        );
        $stmt->execute($ids);
        /** @var array<int, array<string, mixed>> $byId */
        $byId = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $byId[(int) $row['id']] = $row;
        }

        $base = $publicSiteUrl !== null && $publicSiteUrl !== '' ? rtrim($publicSiteUrl, '/') : '';
        $html = '<ul class="content-entry-refs-list">';
        foreach ($ids as $id) {
            $row = $byId[$id] ?? null;
            if ($row === null) {
                $html .= '<li><span class="content-entry-ref-missing">' . $e('Entry #' . $id . ' (missing)') . '</span></li>';

                continue;
            }
            $entry = ContentEntry::fromRow($row);
            $typeSlug = (string) ($row['type_slug'] ?? '');
            $slug = (string) ($row['slug'] ?? '');
            $hasRoute = ((int) ($row['has_public_route'] ?? 0)) === 1;
            $path = '/' . rawurlencode($typeSlug) . '/' . rawurlencode($slug);
            $visible = $hasRoute && $entry->isPubliclyVisible();
            if ($visible && $base !== '') {
                $html .= '<li><a href="' . $e($base . $path) . '">' . $e($entry->title) . '</a></li>';
            } elseif ($visible) {
                $html .= '<li><a href="' . $e($path) . '">' . $e($entry->title) . '</a></li>';
            } else {
                $html .= '<li><span class="content-entry-ref-offline">' . $e($entry->title) . ' <em>(' . $e('not public') . ')</em></span></li>';
            }
        }
        $html .= '</ul>';

        return $html;
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
