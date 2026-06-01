<?php

declare(strict_types=1);

namespace App\Config;

use App\Access\RoleRepository;
use App\Blueprint\StructureCollector;
use App\Commerce\Coupon\CouponRepository;
use App\Commerce\Shipping\ShippingZoneRepository;
use App\Commerce\Tax\TaxRateRepository;
use App\Mobile\MobileSettings;
use App\Settings\SettingsRepository;
use PDO;

/**
 * Structure export including CMI-lite scopes (roles, mobile, commerce).
 */
final class ConfigStructureExporter
{
    public const STRUCTURE_VERSION = '1.1';

    public function __construct(
        private readonly StructureCollector $collector,
        private readonly PDO $pdo,
    ) {
    }

    /**
     * @param list<string> $scopes
     * @return array<string, mixed>
     */
    public function collect(array $scopes, bool $includeEntries, int $maxEntriesPerType = 200): array
    {
        $scopes = ConfigPackageRegistry::normalizeScopes($scopes);
        $legacy = array_values(array_intersect($scopes, ['meta', 'settings', 'menus', 'content_types', 'entries']));
        $base = $this->collector->collectPartial($legacy, $includeEntries, $maxEntriesPerType);
        $base['cms_structure_export_version'] = self::STRUCTURE_VERSION;

        $scopeSet = array_flip($scopes);
        if (isset($scopeSet['roles'])) {
            $base['roles'] = $this->exportRoles();
        }
        if (isset($scopeSet['mobile'])) {
            $base['mobile_settings'] = $this->exportMobile();
        }
        if (isset($scopeSet['commerce'])) {
            $base['commerce'] = $this->exportCommerce();
        }

        return $base;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function exportRoles(): array
    {
        $roles = new RoleRepository($this->pdo);
        $permIdToSlug = [];
        foreach ($roles->allPermissions() as $p) {
            $permIdToSlug[(int) ($p['id'] ?? 0)] = (string) ($p['slug'] ?? '');
        }
        $out = [];
        foreach ($roles->allOrdered() as $row) {
            $roleId = (int) ($row['id'] ?? 0);
            $slugs = [];
            foreach ($roles->permissionIdsForRole($roleId) as $pid) {
                if (isset($permIdToSlug[$pid]) && $permIdToSlug[$pid] !== '') {
                    $slugs[] = $permIdToSlug[$pid];
                }
            }
            sort($slugs);
            $out[] = [
                'slug' => (string) ($row['slug'] ?? ''),
                'name' => (string) ($row['name'] ?? ''),
                'description' => isset($row['description']) ? (string) $row['description'] : null,
                'is_system' => !empty($row['is_system']),
                'permission_slugs' => $slugs,
            ];
        }

        return $out;
    }

    /**
     * @return array<string, string>
     */
    private function exportMobile(): array
    {
        $repo = new SettingsRepository($this->pdo);
        $all = $repo->allKeyValues();
        $out = [];
        foreach (MobileSettings::exportableKeys() as $key) {
            if (array_key_exists($key, $all)) {
                $out[$key] = (string) $all[$key];
            }
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function exportCommerce(): array
    {
        $zones = new ShippingZoneRepository($this->pdo);
        $tax = new TaxRateRepository($this->pdo);
        $coupons = new CouponRepository($this->pdo);

        $zoneRows = [];
        foreach ($zones->listAll() as $z) {
            $zoneRows[] = [
                'name' => $z->name,
                'label' => $z->label,
                'price_cents' => $z->priceCents,
                'free_shipping_min_cents' => $z->freeShippingMinCents,
                'countries' => $z->countries,
                'sort_order' => $z->sortOrder,
                'is_active' => $z->isActive,
            ];
        }

        $taxRows = [];
        foreach ($tax->listAll() as $t) {
            $taxRows[] = [
                'country_code' => $t->countryCode,
                'label' => $t->label,
                'rate_bps' => $t->rateBps,
                'is_active' => $t->isActive,
                'sort_order' => $t->sortOrder,
            ];
        }

        $couponRows = [];
        foreach ($coupons->listAll() as $c) {
            $couponRows[] = [
                'code' => $c->code,
                'discount_type' => $c->discountType,
                'amount' => $c->amount,
                'min_subtotal_cents' => $c->minSubtotalCents,
                'max_uses' => $c->maxUses,
                'active' => $c->active,
                'expires_at' => $c->expiresAt,
            ];
        }

        return [
            'shipping_zones' => $zoneRows,
            'tax_rates' => $taxRows,
            'coupons' => $couponRows,
        ];
    }
}
