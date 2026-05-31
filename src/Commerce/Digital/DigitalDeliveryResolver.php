<?php

declare(strict_types=1);

namespace App\Commerce\Digital;

use App\Commerce\CommerceSettings;
use App\Content\ContentEntryRepository;
use App\Content\ContentEntryValueRepository;
use App\Content\ContentFieldRepository;
use App\Content\ContentTypeRepository;
use App\Media\MediaRepository;

final class DigitalDeliveryResolver
{
    public function __construct(
        private readonly CommerceSettings $commerce,
        private readonly ContentTypeRepository $types,
        private readonly ContentFieldRepository $fields,
        private readonly ContentEntryValueRepository $values,
        private readonly ContentEntryRepository $entries,
        private readonly MediaRepository $media,
    ) {
    }

    public function forEntryId(int $entryId): ?DigitalDeliverySpec
    {
        $entry = $this->entries->findById($entryId);
        if ($entry === null) {
            return null;
        }
        $type = $this->types->findById($entry->contentTypeId);
        if ($type === null || $type->slug !== $this->commerce->productTypeSlug()) {
            return null;
        }

        return $this->forEntry($type->id, $type->slug, $entryId);
    }

    public function forEntry(int $contentTypeId, string $typeSlug, int $entryId): ?DigitalDeliverySpec
    {
        $fieldMap = $this->fieldKeyMap($contentTypeId);
        $valueMap = $this->values->valuesByFieldIdForEntry($entryId);
        $label = $this->optionalString($this->valueForKey($valueMap, $fieldMap, CommerceSettings::FIELD_DIGITAL_LABEL))
            ?? 'Download';

        $fileRaw = $this->valueForKey($valueMap, $fieldMap, CommerceSettings::FIELD_DIGITAL_FILE);
        if ($fileRaw !== null && ctype_digit(trim($fileRaw))) {
            $mediaId = (int) trim($fileRaw);
            if ($mediaId > 0 && $this->media->findById($mediaId) !== null) {
                return new DigitalDeliverySpec(DigitalDeliverySpec::TYPE_FILE, ['media_id' => $mediaId], $label);
            }
        }

        $url = $this->optionalString($this->valueForKey($valueMap, $fieldMap, CommerceSettings::FIELD_DIGITAL_URL));
        if ($url !== null && filter_var($url, FILTER_VALIDATE_URL) !== false) {
            return new DigitalDeliverySpec(DigitalDeliverySpec::TYPE_URL, ['url' => $url], $label);
        }

        $entrySlug = $this->optionalString($this->valueForKey($valueMap, $fieldMap, CommerceSettings::FIELD_DIGITAL_ENTRY_SLUG));
        if ($entrySlug !== null && $this->entries->findPublishedByTypeSlug($contentTypeId, $entrySlug) !== null) {
            return new DigitalDeliverySpec(DigitalDeliverySpec::TYPE_ENTRY, [
                'type_slug' => $typeSlug,
                'entry_slug' => $entrySlug,
            ], $label);
        }

        return null;
    }

    /**
     * @return array<string, int>
     */
    private function fieldKeyMap(int $contentTypeId): array
    {
        $map = [];
        foreach ($this->fields->forTypeOrdered($contentTypeId) as $field) {
            $map[$field->fieldKey] = $field->id;
        }

        return $map;
    }

    /**
     * @param array<int, string> $valueMap
     * @param array<string, int> $fieldMap
     */
    private function valueForKey(array $valueMap, array $fieldMap, string $key): ?string
    {
        if (!isset($fieldMap[$key])) {
            return null;
        }

        return array_key_exists($fieldMap[$key], $valueMap) ? (string) $valueMap[$fieldMap[$key]] : null;
    }

    private function optionalString(?string $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $v = trim($v);

        return $v !== '' ? $v : null;
    }
}
