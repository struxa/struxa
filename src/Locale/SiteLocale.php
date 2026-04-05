<?php

declare(strict_types=1);

namespace App\Locale;

/**
 * Public site language for HTML lang, Open Graph locale, and related metadata.
 * UI copy remains English; this targets document language and SEO hints.
 */
final class SiteLocale
{
    /**
     * @return list<array{value: string, label: string}>
     */
    public static function formOptions(): array
    {
        return [
            ['value' => 'en', 'label' => 'English (US) — en'],
            ['value' => 'en-GB', 'label' => 'English (UK) — en-GB'],
            ['value' => 'en-AU', 'label' => 'English (Australia) — en-AU'],
            ['value' => 'de', 'label' => 'German — de'],
            ['value' => 'fr', 'label' => 'French — fr'],
            ['value' => 'es', 'label' => 'Spanish — es'],
            ['value' => 'es-MX', 'label' => 'Spanish (Mexico) — es-MX'],
            ['value' => 'it', 'label' => 'Italian — it'],
            ['value' => 'pt', 'label' => 'Portuguese (Portugal) — pt'],
            ['value' => 'pt-BR', 'label' => 'Portuguese (Brazil) — pt-BR'],
            ['value' => 'nl', 'label' => 'Dutch — nl'],
            ['value' => 'pl', 'label' => 'Polish — pl'],
            ['value' => 'sv', 'label' => 'Swedish — sv'],
            ['value' => 'da', 'label' => 'Danish — da'],
            ['value' => 'fi', 'label' => 'Finnish — fi'],
            ['value' => 'nb', 'label' => 'Norwegian Bokmål — nb'],
            ['value' => 'ja', 'label' => 'Japanese — ja'],
            ['value' => 'ko', 'label' => 'Korean — ko'],
            ['value' => 'zh-CN', 'label' => 'Chinese (Simplified) — zh-CN'],
            ['value' => 'zh-TW', 'label' => 'Chinese (Traditional) — zh-TW'],
            ['value' => 'ru', 'label' => 'Russian — ru'],
            ['value' => 'uk', 'label' => 'Ukrainian — uk'],
            ['value' => 'tr', 'label' => 'Turkish — tr'],
            ['value' => 'ar', 'label' => 'Arabic — ar'],
            ['value' => 'he', 'label' => 'Hebrew — he'],
            ['value' => 'hi', 'label' => 'Hindi — hi'],
        ];
    }

    /**
     * @return list<string>
     */
    public static function allowedCodes(): array
    {
        $out = [];
        foreach (self::formOptions() as $row) {
            $out[] = $row['value'];
        }

        return $out;
    }

    public static function normalizeSetting(string $raw): string
    {
        $s = strtolower(trim(str_replace('_', '-', $raw)));
        if ($s === '') {
            return 'en';
        }
        foreach (self::allowedCodes() as $code) {
            if (strcasecmp($s, str_replace('_', '-', $code)) === 0) {
                return $code;
            }
        }

        return 'en';
    }

    /** BCP 47 language tag for &lt;html lang&gt; */
    public static function htmlLang(string $normalizedCode): string
    {
        return $normalizedCode === '' ? 'en' : $normalizedCode;
    }

    /** Open Graph locale (underscore form). */
    public static function ogLocale(string $htmlLang): string
    {
        $k = strtolower(str_replace('_', '-', trim($htmlLang)));
        $map = [
            'en' => 'en_US',
            'en-gb' => 'en_GB',
            'en-au' => 'en_AU',
            'de' => 'de_DE',
            'fr' => 'fr_FR',
            'es' => 'es_ES',
            'es-mx' => 'es_MX',
            'it' => 'it_IT',
            'pt' => 'pt_PT',
            'pt-br' => 'pt_BR',
            'nl' => 'nl_NL',
            'pl' => 'pl_PL',
            'sv' => 'sv_SE',
            'da' => 'da_DK',
            'fi' => 'fi_FI',
            'nb' => 'nb_NO',
            'ja' => 'ja_JP',
            'ko' => 'ko_KR',
            'zh-cn' => 'zh_CN',
            'zh-tw' => 'zh_TW',
            'ru' => 'ru_RU',
            'uk' => 'uk_UA',
            'tr' => 'tr_TR',
            'ar' => 'ar_SA',
            'he' => 'he_IL',
            'hi' => 'hi_IN',
        ];
        if (isset($map[$k])) {
            return $map[$k];
        }
        if (preg_match('/^([a-z]{2,3})-([a-z]{2})$/', $k, $m) === 1) {
            return $m[1] . '_' . strtoupper($m[2]);
        }
        if (preg_match('/^([a-z]{2,3})$/', $k, $m) === 1) {
            return $m[1] . '_' . strtoupper($m[1]);
        }

        return 'en_US';
    }
}
