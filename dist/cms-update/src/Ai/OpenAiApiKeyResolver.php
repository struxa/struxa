<?php

declare(strict_types=1);

namespace App\Ai;

use App\Settings;

final class OpenAiApiKeyResolver
{
    /**
     * Environment overrides DB (OPENAI_API_KEY or STRUXA_OPENAI_API_KEY).
     */
    public static function resolve(): string
    {
        foreach (['OPENAI_API_KEY', 'STRUXA_OPENAI_API_KEY'] as $key) {
            $v = $_ENV[$key] ?? null;
            if (is_string($v) && trim($v) !== '') {
                return trim($v);
            }
            $g = getenv($key);
            if (is_string($g) && trim($g) !== '') {
                return trim($g);
            }
        }

        $db = Settings::get('openai_api_key', '') ?? '';

        return trim((string) $db);
    }

    public static function isEnabledInSettings(): bool
    {
        $v = Settings::get('openai_enabled', '0') ?? '0';

        return trim((string) $v) === '1';
    }

    public static function storedModel(): string
    {
        $m = Settings::get('openai_model', 'gpt-4o-mini') ?? 'gpt-4o-mini';
        $m = trim((string) $m);

        return $m !== '' ? $m : 'gpt-4o-mini';
    }

    /** OPENAI_MODEL / STRUXA_OPENAI_MODEL override {@see storedModel()}. */
    public static function activeModel(): string
    {
        foreach (['OPENAI_MODEL', 'STRUXA_OPENAI_MODEL'] as $key) {
            $v = $_ENV[$key] ?? null;
            if (is_string($v) && trim($v) !== '') {
                return trim($v);
            }
            $g = getenv($key);
            if (is_string($g) && trim($g) !== '') {
                return trim($g);
            }
        }

        return self::storedModel();
    }

    public static function canGenerate(): bool
    {
        return self::isEnabledInSettings() && self::resolve() !== '';
    }

    public static function hasEnvApiKey(): bool
    {
        foreach (['OPENAI_API_KEY', 'STRUXA_OPENAI_API_KEY'] as $key) {
            $v = $_ENV[$key] ?? null;
            if (is_string($v) && trim($v) !== '') {
                return true;
            }
            $g = getenv($key);
            if (is_string($g) && trim($g) !== '') {
                return true;
            }
        }

        return false;
    }
}
