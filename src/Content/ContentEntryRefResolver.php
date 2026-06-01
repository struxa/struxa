<?php

declare(strict_types=1);

namespace App\Content;

/**
 * Resolve entry_refs stored JSON (ID list) into structured summaries for API, Twig, and admin UI.
 */
final class ContentEntryRefResolver
{
    public function __construct(
        private readonly ContentEntryRepository $entries,
        private readonly ContentTypeRepository $types,
    ) {
    }

    /**
     * @param list<int> $ids
     * @return list<array{
     *     id: int,
     *     title: string,
     *     slug: string,
     *     status: string,
     *     content_type_id: int,
     *     type_slug: string,
     *     type_name: string,
     *     is_public: bool,
     *     public_path: ?string,
     *     public_url: ?string
     * }>
     */
    public function resolvePublic(array $ids, string $siteUrl = ''): array
    {
        $ids = ContentEntryReferenceIds::dedupeIds($ids);
        if ($ids === []) {
            return [];
        }
        $rows = $this->entries->fetchRowsByIds($ids);
        $base = $siteUrl !== '' ? rtrim($siteUrl, '/') : '';
        $out = [];
        foreach ($ids as $id) {
            $row = $rows[$id] ?? null;
            if ($row === null) {
                continue;
            }
            $entry = ContentEntry::fromRow($row);
            $typeId = (int) ($row['content_type_id'] ?? 0);
            $type = $this->types->findById($typeId);
            $typeSlug = $type !== null ? $type->slug : '';
            $typeName = $type !== null ? $type->name : '';
            $hasRoute = $type !== null && $type->hasPublicRoute;
            $path = $hasRoute && $typeSlug !== '' && $entry->slug !== ''
                ? '/' . rawurlencode($typeSlug) . '/' . rawurlencode($entry->slug)
                : null;
            $isPublic = $hasRoute && $entry->isPubliclyVisible();
            $out[] = [
                'id' => $id,
                'title' => $entry->title,
                'slug' => $entry->slug,
                'status' => $entry->status,
                'content_type_id' => $typeId,
                'type_slug' => $typeSlug,
                'type_name' => $typeName,
                'is_public' => $isPublic,
                'public_path' => $path,
                'public_url' => $path !== null && $base !== '' ? $base . $path : $path,
            ];
        }

        return $out;
    }

    /**
     * @param list<int> $ids
     * @return list<array{
     *     id: int,
     *     title: string,
     *     slug: string,
     *     status: string,
     *     content_type_id: int,
     *     type_slug: string,
     *     type_name: string,
     *     is_public: bool,
     *     edit_url: ?string
     * }>
     */
    public function resolveAdmin(array $ids, ?callable $editUrlFor = null): array
    {
        $public = $this->resolvePublic($ids);
        $out = [];
        foreach ($public as $item) {
            $editUrl = null;
            if ($editUrlFor !== null) {
                $editUrl = $editUrlFor($item['id'], $item['content_type_id']);
            }
            $out[] = [
                'id' => $item['id'],
                'title' => $item['title'],
                'slug' => $item['slug'],
                'status' => $item['status'],
                'content_type_id' => $item['content_type_id'],
                'type_slug' => $item['type_slug'],
                'type_name' => $item['type_name'],
                'is_public' => $item['is_public'],
                'edit_url' => $editUrl,
            ];
        }

        return $out;
    }

    /**
     * @param list<ContentField> $fields
     * @param array<int, string|null> $valueMap
     * @return array<int, list<array<string, mixed>>>
     */
    public function resolveMapForFields(array $fields, array $valueMap, ?callable $editUrlFor = null): array
    {
        $map = [];
        foreach ($fields as $field) {
            if ($field->fieldType !== 'entry_refs') {
                continue;
            }
            $raw = $valueMap[$field->id] ?? null;
            if (is_array($raw)) {
                $coerced = [];
                foreach ($raw as $v) {
                    if (is_int($v) && $v > 0) {
                        $coerced[] = $v;
                    } elseif (is_string($v) && ctype_digit(trim($v))) {
                        $coerced[] = (int) trim($v);
                    }
                }
                try {
                    $raw = ContentEntryReferenceIds::toJson($coerced);
                } catch (\JsonException) {
                    $raw = '[]';
                }
            }
            $ids = ContentEntryReferenceIds::parse($raw !== null ? (string) $raw : null);
            $map[$field->id] = $this->resolveAdmin($ids, $editUrlFor);
        }

        return $map;
    }
}
