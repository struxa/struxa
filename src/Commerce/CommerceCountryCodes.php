<?php

declare(strict_types=1);

namespace App\Commerce;

/** Common ISO 3166-1 alpha-2 codes for cart/admin selects. */
final class CommerceCountryCodes
{
    /**
     * @return list<array{code: string, name: string}>
     */
    public static function common(): array
    {
        return [
            ['code' => 'GB', 'name' => 'United Kingdom'],
            ['code' => 'IE', 'name' => 'Ireland'],
            ['code' => 'US', 'name' => 'United States'],
            ['code' => 'CA', 'name' => 'Canada'],
            ['code' => 'AU', 'name' => 'Australia'],
            ['code' => 'NZ', 'name' => 'New Zealand'],
            ['code' => 'DE', 'name' => 'Germany'],
            ['code' => 'FR', 'name' => 'France'],
            ['code' => 'ES', 'name' => 'Spain'],
            ['code' => 'IT', 'name' => 'Italy'],
            ['code' => 'NL', 'name' => 'Netherlands'],
            ['code' => 'BE', 'name' => 'Belgium'],
            ['code' => 'SE', 'name' => 'Sweden'],
            ['code' => 'NO', 'name' => 'Norway'],
            ['code' => 'DK', 'name' => 'Denmark'],
            ['code' => 'FI', 'name' => 'Finland'],
            ['code' => 'CH', 'name' => 'Switzerland'],
            ['code' => 'AT', 'name' => 'Austria'],
            ['code' => 'PT', 'name' => 'Portugal'],
            ['code' => 'PL', 'name' => 'Poland'],
        ];
    }

    /**
     * @param list<string> $preferredCodes
     * @return list<array{code: string, name: string}>
     */
    public static function forSelect(array $preferredCodes = []): array
    {
        $byCode = [];
        foreach (self::common() as $row) {
            $byCode[$row['code']] = $row;
        }
        foreach ($preferredCodes as $code) {
            $c = strtoupper(trim($code));
            if ($c !== '' && preg_match('/^[A-Z]{2}$/', $c) === 1 && !isset($byCode[$c])) {
                $byCode[$c] = ['code' => $c, 'name' => $c];
            }
        }
        ksort($byCode);

        return array_values($byCode);
    }
}
