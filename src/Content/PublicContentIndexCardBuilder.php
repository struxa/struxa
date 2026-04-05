<?php

declare(strict_types=1);

namespace App\Content;

use App\Media\MediaUrlHelper;

/**
 * Builds card payloads for public content-type index UIs (archive + CMS sections).
 *
 * @phpstan-type IndexCard array{row: array<string, mixed>, excerpt_plain: string, featured_url: string, score_raw: string, price_plain: string, buy_url: string, buy_label: string}
 */
final class PublicContentIndexCardBuilder
{
    public function __construct(
        private readonly ContentFieldRepository $fields,
        private readonly ContentEntryValueRepository $values,
        private readonly MediaUrlHelper $mediaUrls,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $rows From ContentEntryRepository::publishedForContentTypePaged
     * @return list<IndexCard>
     */
    public function buildForEntries(ContentType $type, array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $previewFieldId = null;
        foreach ($this->fields->forTypeOrdered($type->id) as $f) {
            if ($f->fieldKey === 'excerpt') {
                $previewFieldId = $f->id;
                break;
            }
        }
        if ($previewFieldId === null) {
            foreach ($this->fields->forTypeOrdered($type->id) as $f) {
                if ($f->fieldKey === 'summary') {
                    $previewFieldId = $f->id;
                    break;
                }
            }
        }
        if ($previewFieldId === null) {
            foreach ($this->fields->forTypeOrdered($type->id) as $f) {
                if ($f->fieldKey === 'role') {
                    $previewFieldId = $f->id;
                    break;
                }
            }
        }
        $thumbFieldId = null;
        foreach (['thumbnail_url', 'card_image_url'] as $thumbKey) {
            foreach ($this->fields->forTypeOrdered($type->id) as $f) {
                if ($f->fieldKey === $thumbKey) {
                    $thumbFieldId = $f->id;
                    break 2;
                }
            }
        }
        $entryIds = array_map(static fn (array $r): int => (int) $r['id'], $rows);
        $previews = $previewFieldId !== null ? $this->values->valuesForFieldAndEntryIds($previewFieldId, $entryIds) : [];
        $thumbUrls = $thumbFieldId !== null ? $this->values->valuesForFieldAndEntryIds($thumbFieldId, $entryIds) : [];

        $scoreFieldId = null;
        foreach ($this->fields->forTypeOrdered($type->id) as $f) {
            if ($f->fieldKey === 'score') {
                $scoreFieldId = $f->id;
                break;
            }
        }
        $scores = $scoreFieldId !== null ? $this->values->valuesForFieldAndEntryIds($scoreFieldId, $entryIds) : [];

        $priceFieldId = null;
        foreach (['price_display', 'price'] as $priceKey) {
            foreach ($this->fields->forTypeOrdered($type->id) as $f) {
                if ($f->fieldKey === $priceKey) {
                    $priceFieldId = $f->id;
                    break 2;
                }
            }
        }
        $prices = $priceFieldId !== null ? $this->values->valuesForFieldAndEntryIds($priceFieldId, $entryIds) : [];

        $buyFieldKeysPriority = ['buy_url', 'checkout_url', 'cta_url', 'purchase_url'];
        /** @var array<string, array{id: int, label: string}> $buyFields */
        $buyFields = [];
        foreach ($this->fields->forTypeOrdered($type->id) as $f) {
            if (in_array($f->fieldKey, $buyFieldKeysPriority, true)) {
                $buyFields[$f->fieldKey] = ['id' => $f->id, 'label' => $f->label];
            }
        }
        /** @var array<string, array<int, string>> $buyValuesByKey */
        $buyValuesByKey = [];
        foreach ($buyFields as $key => $meta) {
            $buyValuesByKey[$key] = $this->values->valuesForFieldAndEntryIds($meta['id'], $entryIds);
        }

        $indexRows = [];
        foreach ($rows as $row) {
            $eid = (int) $row['id'];
            $ex = $previews[$eid] ?? '';
            $exStripped = strip_tags($ex);
            $excerptShort = $exStripped !== ''
                ? (mb_strlen($exStripped) > 180 ? mb_substr($exStripped, 0, 177) . '…' : $exStripped)
                : '';
            $fid = $row['featured_image_id'] ?? null;
            $featuredUrl = $fid !== null && $fid !== '' ? $this->mediaUrls->pathForId((int) $fid) : '';
            if ($featuredUrl === '' && isset($thumbUrls[$eid])) {
                $ext = trim($thumbUrls[$eid]);
                if ($ext !== '') {
                    if (preg_match('#^https?://#i', $ext) === 1) {
                        $featuredUrl = $ext;
                    } elseif ($ext[0] === '/' || $ext[0] === '.') {
                        $featuredUrl = $ext;
                    }
                }
            }
            $buyUrl = '';
            $buyLabel = '';
            foreach ($buyFieldKeysPriority as $bk) {
                if (!isset($buyFields[$bk], $buyValuesByKey[$bk])) {
                    continue;
                }
                $rawBuy = trim((string) ($buyValuesByKey[$bk][$eid] ?? ''));
                if ($rawBuy !== '') {
                    $buyUrl = $rawBuy;
                    $buyLabel = $buyFields[$bk]['label'];
                    break;
                }
            }
            $indexRows[] = [
                'row' => $row,
                'excerpt_plain' => $excerptShort,
                'featured_url' => $featuredUrl,
                'score_raw' => $scores[$eid] ?? '',
                'price_plain' => $prices[$eid] ?? '',
                'buy_url' => $buyUrl,
                'buy_label' => $buyLabel,
            ];
        }

        return $indexRows;
    }
}
