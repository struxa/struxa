<?php

declare(strict_types=1);

namespace App\Config;

use App\Access\RoleRepository;
use App\Commerce\Coupon\CouponRepository;
use App\Commerce\Shipping\ShippingZoneRepository;
use App\Commerce\Tax\TaxRateRepository;
use App\Mobile\MobileSettings;
use App\Settings\SettingsRepository;
use PDO;

/**
 * Import scopes not covered by blueprint structure (roles, mobile, commerce).
 */
final class ConfigExtendedImporter
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly SettingsRepository $settings,
        private readonly RoleRepository $roles,
        private readonly ShippingZoneRepository $shippingZones,
        private readonly TaxRateRepository $taxRates,
        private readonly CouponRepository $coupons,
    ) {
    }

    /**
     * @param array<string, mixed> $payload structure export payload
     * @param list<string> $scopes normalized scope ids
     * @return array{applied: list<string>, warnings: list<string>}
     */
    public function apply(array $payload, array $scopes, bool $dryRun, bool $merge): array
    {
        $scopes = array_flip($scopes);
        $applied = [];
        $warnings = [];

        if (isset($scopes['mobile']) && isset($payload['mobile_settings']) && is_array($payload['mobile_settings'])) {
            $n = 0;
            foreach (MobileSettings::exportableKeys() as $key) {
                if (!array_key_exists($key, $payload['mobile_settings'])) {
                    continue;
                }
                $v = $payload['mobile_settings'][$key];
                if (!is_string($v)) {
                    continue;
                }
                ++$n;
                if (!$dryRun) {
                    $this->settings->upsert($key, $v, true);
                }
            }
            if ($n > 0) {
                $applied[] = ($dryRun ? 'Would merge ' : 'Merged ') . $n . ' mobile setting key(s)';
            }
        }

        if (isset($scopes['roles']) && isset($payload['roles']) && is_array($payload['roles'])) {
            $this->importRoles($payload['roles'], $dryRun, $merge, $applied, $warnings);
        }

        if (isset($scopes['commerce']) && isset($payload['commerce']) && is_array($payload['commerce'])) {
            $this->importCommerce($payload['commerce'], $dryRun, $merge, $applied, $warnings);
        }

        return ['applied' => $applied, 'warnings' => $warnings];
    }

    /**
     * @param list<mixed> $rolesPayload
     * @param list<string> $applied
     * @param list<string> $warnings
     */
    private function importRoles(array $rolesPayload, bool $dryRun, bool $merge, array &$applied, array &$warnings): void
    {
        $permSlugToId = [];
        foreach ($this->roles->allPermissions() as $p) {
            $slug = (string) ($p['slug'] ?? '');
            if ($slug !== '') {
                $permSlugToId[$slug] = (int) ($p['id'] ?? 0);
            }
        }

        $created = 0;
        $updated = 0;
        foreach ($rolesPayload as $row) {
            if (!is_array($row)) {
                continue;
            }
            $slug = trim((string) ($row['slug'] ?? ''));
            if ($slug === '') {
                continue;
            }
            $name = trim((string) ($row['name'] ?? $slug));
            $description = isset($row['description']) && is_string($row['description']) ? $row['description'] : null;
            $permIds = [];
            foreach ($row['permission_slugs'] ?? [] as $ps) {
                if (is_string($ps) && isset($permSlugToId[$ps])) {
                    $permIds[] = $permSlugToId[$ps];
                }
            }
            $existing = $this->roles->findBySlug($slug);
            if ($existing === null) {
                if (!$dryRun) {
                    $roleId = $this->roles->insert($name, $slug, $description);
                    $this->roles->syncPermissions($roleId, $permIds);
                }
                ++$created;
            } else {
                $isSystem = !empty($existing['is_system']);
                if (!$merge && !$isSystem) {
                    $warnings[] = 'Skipped existing role: ' . $slug;

                    continue;
                }
                if (!$dryRun) {
                    $roleId = (int) $existing['id'];
                    if (!$isSystem) {
                        $this->roles->update($roleId, $name, $slug, $description);
                    }
                    $this->roles->syncPermissions($roleId, $permIds);
                }
                ++$updated;
            }
        }
        if ($created > 0) {
            $applied[] = ($dryRun ? 'Would create ' : 'Created ') . $created . ' role(s)';
        }
        if ($updated > 0) {
            $applied[] = ($dryRun ? 'Would update ' : 'Updated ') . $updated . ' role(s)';
        }
    }

    /**
     * @param array<string, mixed> $commerce
     * @param list<string> $applied
     * @param list<string> $warnings
     */
    private function importCommerce(array $commerce, bool $dryRun, bool $merge, array &$applied, array &$warnings): void
    {
        $zones = is_array($commerce['shipping_zones'] ?? null) ? $commerce['shipping_zones'] : [];
        $zc = 0;
        foreach ($zones as $z) {
            if (!is_array($z)) {
                continue;
            }
            $name = trim((string) ($z['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $existing = null;
            foreach ($this->shippingZones->listAll() as $zone) {
                if ($zone->name === $name) {
                    $existing = $zone;
                    break;
                }
            }
            $data = [
                'name' => $name,
                'label' => (string) ($z['label'] ?? $name),
                'price_cents' => (int) ($z['price_cents'] ?? 0),
                'free_shipping_min_cents' => (int) ($z['free_shipping_min_cents'] ?? 0),
                'countries' => is_array($z['countries'] ?? null) ? $z['countries'] : [],
                'sort_order' => (int) ($z['sort_order'] ?? 0),
                'is_active' => !empty($z['is_active']),
            ];
            if ($existing === null) {
                if (!$dryRun) {
                    $this->shippingZones->create($data);
                }
                ++$zc;
            } elseif ($merge && !$dryRun) {
                $this->shippingZones->update($existing->id, $data);
                ++$zc;
            }
        }
        if ($zc > 0) {
            $applied[] = ($dryRun ? 'Would sync ' : 'Synced ') . $zc . ' shipping zone(s)';
        }

        $taxes = is_array($commerce['tax_rates'] ?? null) ? $commerce['tax_rates'] : [];
        $tc = 0;
        foreach ($taxes as $t) {
            if (!is_array($t)) {
                continue;
            }
            $cc = strtoupper(trim((string) ($t['country_code'] ?? '')));
            if ($cc === '') {
                continue;
            }
            $existing = null;
            foreach ($this->taxRates->listAll() as $rate) {
                if ($rate->countryCode === $cc) {
                    $existing = $rate;
                    break;
                }
            }
            $data = [
                'country_code' => $cc,
                'label' => (string) ($t['label'] ?? $cc),
                'rate_bps' => (int) ($t['rate_bps'] ?? 0),
                'is_active' => !empty($t['is_active']),
                'sort_order' => (int) ($t['sort_order'] ?? 0),
            ];
            if ($existing === null) {
                if (!$dryRun) {
                    $this->taxRates->create($data);
                }
                ++$tc;
            } elseif ($merge && !$dryRun) {
                $this->taxRates->update($existing->id, $data);
                ++$tc;
            }
        }
        if ($tc > 0) {
            $applied[] = ($dryRun ? 'Would sync ' : 'Synced ') . $tc . ' tax rate(s)';
        }

        $coupons = is_array($commerce['coupons'] ?? null) ? $commerce['coupons'] : [];
        $ccount = 0;
        foreach ($coupons as $c) {
            if (!is_array($c)) {
                continue;
            }
            $code = strtoupper(trim((string) ($c['code'] ?? '')));
            if ($code === '') {
                continue;
            }
            $existing = $this->coupons->findByCode($code);
            $data = [
                'code' => $code,
                'discount_type' => (string) ($c['discount_type'] ?? 'percent'),
                'amount' => (int) ($c['amount'] ?? 0),
                'min_subtotal_cents' => (int) ($c['min_subtotal_cents'] ?? 0),
                'max_uses' => isset($c['max_uses']) && $c['max_uses'] !== null ? (int) $c['max_uses'] : null,
                'active' => !empty($c['active']),
                'expires_at' => isset($c['expires_at']) && is_string($c['expires_at']) && $c['expires_at'] !== ''
                    ? $c['expires_at'] : null,
            ];
            if ($existing === null) {
                if (!$dryRun) {
                    $this->coupons->create($data);
                }
                ++$ccount;
            } elseif ($merge && !$dryRun) {
                $this->coupons->update($existing->id, $data);
                ++$ccount;
            }
        }
        if ($ccount > 0) {
            $applied[] = ($dryRun ? 'Would sync ' : 'Synced ') . $ccount . ' coupon(s)';
        }
    }
}
