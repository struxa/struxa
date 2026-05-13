<?php

declare(strict_types=1);

namespace App\Content;

/**
 * Validates linked entry IDs for custom fields of type entry_refs.
 */
final class ContentEntryRefsGuard
{
    /**
     * @param list<ContentField> $fields
     * @param array<int, string|null> $customByFieldId normalized values from ContentFieldValueNormalizer
     * @return array<string, string> errors keyed field_{id}
     */
    public static function validate(
        array $fields,
        array $customByFieldId,
        string $status,
        ?int $currentEntryId,
        ContentEntryRepository $entries,
        ContentTypeRepository $types,
    ): array {
        $errors = [];
        $isPublishing = $status === 'published';

        foreach ($fields as $field) {
            if ($field->fieldType !== 'entry_refs') {
                continue;
            }
            $key = 'field_' . $field->id;
            $raw = $customByFieldId[$field->id] ?? null;
            $ids = ContentEntryReferenceIds::parse($raw !== null ? (string) $raw : null);
            $opts = ContentEntryRefsFieldOptions::fromField($field);
            if (count($ids) > $opts->maxRefs) {
                $errors[$key] = 'Too many linked entries (max ' . $opts->maxRefs . ').';

                continue;
            }
            if ($ids === []) {
                continue;
            }
            $rows = $entries->fetchRowsByIds($ids);
            foreach ($ids as $tid) {
                if ($currentEntryId !== null && $tid === $currentEntryId) {
                    $errors[$key] = 'Cannot link an entry to itself.';

                    break;
                }
                $row = $rows[$tid] ?? null;
                if ($row === null) {
                    $errors[$key] = 'Unknown entry ID #' . $tid . ' (deleted or missing).';

                    break;
                }
                $typeId = (int) ($row['content_type_id'] ?? 0);
                if ($opts->targetContentTypeId !== null && $typeId !== $opts->targetContentTypeId) {
                    $errors[$key] = 'Entry #' . $tid . ' must belong to the configured target content type.';

                    break;
                }
                $t = $types->findById($typeId);
                if ($t === null || !$t->hasPublicRoute) {
                    $errors[$key] = 'Entry #' . $tid . ' has no public route; it cannot be used in public “see also” links.';

                    break;
                }
                $entry = ContentEntry::fromRow($row);
                if ($isPublishing && $opts->requirePublicTargets && !$entry->isPubliclyVisible()) {
                    $errors[$key] = 'Entry #' . $tid . ' is not publicly visible (publish it or adjust “Published at”) before publishing this page.';

                    break;
                }
                if (!$isPublishing && !$entry->isPubliclyVisible()) {
                    // allowed; warnings handled separately
                }
            }
        }

        return $errors;
    }

    /**
     * Non-blocking messages for the entry editor (draft / review states).
     *
     * @param list<ContentField> $fields
     * @param array<int, string|null> $valueMap DB or form values
     * @return list<string>
     */
    public static function warnings(
        array $fields,
        array $valueMap,
        ?int $currentEntryId,
        ContentEntryRepository $entries,
        ContentTypeRepository $types,
    ): array {
        $out = [];
        foreach ($fields as $field) {
            if ($field->fieldType !== 'entry_refs') {
                continue;
            }
            $raw = $valueMap[$field->id] ?? null;
            $ids = ContentEntryReferenceIds::parse($raw !== null ? (string) $raw : null);
            if ($ids === []) {
                continue;
            }
            $opts = ContentEntryRefsFieldOptions::fromField($field);
            if (count($ids) > $opts->maxRefs) {
                $out[] = $field->label . ': more than max ' . $opts->maxRefs . ' links; trim the list before publishing.';
            }
            $rows = $entries->fetchRowsByIds($ids);
            foreach ($ids as $tid) {
                if ($currentEntryId !== null && $tid === $currentEntryId) {
                    $out[] = $field->label . ': remove self-reference (entry #' . $tid . ').';
                }
                $row = $rows[$tid] ?? null;
                if ($row === null) {
                    $out[] = $field->label . ': entry #' . $tid . ' does not exist — fix before publish.';

                    continue;
                }
                $typeId = (int) ($row['content_type_id'] ?? 0);
                if ($opts->targetContentTypeId !== null && $typeId !== $opts->targetContentTypeId) {
                    $out[] = $field->label . ': entry #' . $tid . ' is the wrong content type for this field.';
                }
                $t = $types->findById($typeId);
                if ($t === null || !$t->hasPublicRoute) {
                    $out[] = $field->label . ': entry #' . $tid . ' has no public URL.';
                }
                $entry = ContentEntry::fromRow($row);
                if (!$entry->isPubliclyVisible()) {
                    $out[] = $field->label . ': entry #' . $tid . ' (“' . $entry->title . '”) is not live on the site yet — visitors will see a placeholder until it is published.';
                }
            }
        }

        return $out;
    }
}
