<?php

declare(strict_types=1);

namespace StripeStorePlugin;

use App\Content\ContentEntryRepository;
use App\Content\ContentEntryValueRepository;
use App\Content\ContentFieldRepository;
use App\Content\ContentTypeRepository;
use PDO;

/**
 * Resolves a published content entry into Stripe line-item data using optional fields:
 * - stripe_price_id (text): Stripe Price ID (e.g. price_xxx)
 * - stripe_amount_cents (text/number): integer cents for one-off price_data checkout
 * - stripe_currency (text): optional per-product ISO currency (3 letters)
 */
final class ProductCheckoutResolver
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param list<string> $allowedTypeSlugs lowercased
     * @return array{
     *   type_slug: string,
     *   entry_slug: string,
     *   entry_id: int,
     *   title: string,
     *   stripe_price_id: ?string,
     *   amount_cents: ?int,
     *   currency: ?string
     * }|null
     */
    public function resolve(string $typeSlug, string $entrySlug, array $allowedTypeSlugs): ?array
    {
        $typeSlug = strtolower(trim($typeSlug));
        $entrySlug = trim($entrySlug);
        if ($typeSlug === '' || $entrySlug === '') {
            return null;
        }
        if (!in_array($typeSlug, $allowedTypeSlugs, true)) {
            return null;
        }

        $types = new ContentTypeRepository($this->pdo);
        $type = $types->findBySlug($typeSlug);
        if ($type === null || !$type->hasPublicRoute) {
            return null;
        }

        $entries = new ContentEntryRepository($this->pdo);
        $entry = $entries->findPublishedByTypeSlug($type->id, $entrySlug);
        if ($entry === null) {
            return null;
        }

        $fields = new ContentFieldRepository($this->pdo);
        $fieldList = $fields->forTypeOrdered($type->id);
        $values = new ContentEntryValueRepository($this->pdo);
        $valueMap = $values->valuesByFieldIdForEntry($entry->id);

        $byKey = [];
        foreach ($fieldList as $f) {
            $byKey[$f->fieldKey] = $valueMap[$f->id] ?? '';
        }

        $priceId = trim((string) ($byKey['stripe_price_id'] ?? ''));
        $amountRaw = trim((string) ($byKey['stripe_amount_cents'] ?? ''));
        $currencyOverride = strtolower(trim((string) ($byKey['stripe_currency'] ?? '')));
        if (strlen($currencyOverride) !== 3) {
            $currencyOverride = '';
        }

        $amountCents = null;
        if ($amountRaw !== '' && ctype_digit($amountRaw)) {
            $n = (int) $amountRaw;
            if ($n > 0) {
                $amountCents = $n;
            }
        }

        if ($priceId === '' && $amountCents === null) {
            return null;
        }

        return [
            'type_slug' => $typeSlug,
            'entry_slug' => $entrySlug,
            'entry_id' => $entry->id,
            'title' => $entry->title,
            'stripe_price_id' => $priceId !== '' ? $priceId : null,
            'amount_cents' => $amountCents,
            'currency' => $currencyOverride !== '' ? $currencyOverride : null,
        ];
    }
}
