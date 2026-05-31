<?php

declare(strict_types=1);

namespace App\Commerce\Order;

/** Formats Stripe-collected shipping address arrays for display and email. */
final class ShippingAddressFormatter
{
    /**
     * @param array<string, mixed>|null $data
     * @return list<string>
     */
    public static function lines(?array $data): array
    {
        if ($data === null || $data === []) {
            return [];
        }

        $lines = [];
        $name = isset($data['name']) && is_string($data['name']) ? trim($data['name']) : '';
        if ($name !== '') {
            $lines[] = $name;
        }

        $address = isset($data['address']) && is_array($data['address']) ? $data['address'] : $data;
        if (!is_array($address)) {
            return $lines;
        }

        foreach (['line1', 'line2'] as $key) {
            $v = isset($address[$key]) && is_string($address[$key]) ? trim($address[$key]) : '';
            if ($v !== '') {
                $lines[] = $v;
            }
        }

        $city = isset($address['city']) && is_string($address['city']) ? trim($address['city']) : '';
        $state = isset($address['state']) && is_string($address['state']) ? trim($address['state']) : '';
        $postal = isset($address['postal_code']) && is_string($address['postal_code']) ? trim($address['postal_code']) : '';
        $country = isset($address['country']) && is_string($address['country']) ? strtoupper(trim($address['country'])) : '';

        $locality = trim(implode(', ', array_filter([$city, $state, $postal])));
        if ($locality !== '') {
            $lines[] = $locality;
        }
        if ($country !== '') {
            $lines[] = $country;
        }

        return $lines;
    }

    /**
     * @param array<string, mixed>|null $data
     */
    public static function singleLine(?array $data): string
    {
        return implode(', ', self::lines($data));
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function fromStripeObject(object $details): ?array
    {
        $out = [];
        if (isset($details->name) && is_string($details->name) && trim($details->name) !== '') {
            $out['name'] = trim($details->name);
        }
        if (isset($details->address) && is_object($details->address)) {
            $addr = [];
            foreach (['line1', 'line2', 'city', 'state', 'postal_code', 'country'] as $key) {
                if (isset($details->address->$key) && is_string($details->address->$key) && trim($details->address->$key) !== '') {
                    $addr[$key] = trim($details->address->$key);
                }
            }
            if ($addr !== []) {
                $out['address'] = $addr;
            }
        }

        return $out === [] ? null : $out;
    }
}
