<?php

declare(strict_types=1);

namespace App\Cache;

/**
 * Reads JSON cache files on disk for admin visualization (does not apply TTL eviction).
 */
final class CacheFileInspector
{
    private const MAX_FILES_PER_NAMESPACE = 400;

    /**
     * @return array{rows: list<array<string, mixed>>, truncated: bool, total_files: int}
     */
    public static function listPublicResponseEntries(string $directory): array
    {
        if (!is_dir($directory)) {
            return ['rows' => [], 'truncated' => false, 'total_files' => 0];
        }

        $pattern = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*.json';
        $allFiles = array_values(array_filter(glob($pattern) ?: [], 'is_file'));
        $total = count($allFiles);
        $slice = array_slice($allFiles, 0, self::MAX_FILES_PER_NAMESPACE);

        $out = [];
        foreach ($slice as $file) {
            $row = self::inspectPublicFile($file);
            if ($row !== null) {
                $out[] = $row;
            }
        }

        usort($out, static fn (array $a, array $b): int => (int) ($b['body_bytes'] ?? 0) <=> (int) ($a['body_bytes'] ?? 0));

        return [
            'rows' => $out,
            'truncated' => $total > self::MAX_FILES_PER_NAMESPACE,
            'total_files' => $total,
        ];
    }

    /**
     * @return array{rows: list<array<string, mixed>>, truncated: bool, total_files: int}
     */
    public static function listInternalEntries(string $directory): array
    {
        if (!is_dir($directory)) {
            return ['rows' => [], 'truncated' => false, 'total_files' => 0];
        }

        $pattern = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*.json';
        $allFiles = array_values(array_filter(glob($pattern) ?: [], 'is_file'));
        $total = count($allFiles);
        $slice = array_slice($allFiles, 0, self::MAX_FILES_PER_NAMESPACE);

        $out = [];
        foreach ($slice as $file) {
            $row = self::inspectInternalFile($file);
            if ($row !== null) {
                $out[] = $row;
            }
        }

        usort($out, static fn (array $a, array $b): int => (int) ($b['file_bytes'] ?? 0) <=> (int) ($a['file_bytes'] ?? 0));

        return [
            'rows' => $out,
            'truncated' => $total > self::MAX_FILES_PER_NAMESPACE,
            'total_files' => $total,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function inspectPublicFile(string $file): ?array
    {
        $raw = @file_get_contents($file);
        if ($raw === false || $raw === '') {
            return null;
        }
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($decoded) || !array_key_exists('v', $decoded)) {
            return null;
        }
        $v = $decoded['v'];
        $response = PublicResponseCacheEnvelope::responsePayload($v);
        if ($response === null) {
            return null;
        }

        $meta = PublicResponseCacheEnvelope::meta(is_array($v) ? $v : null);
        $cacheKey = is_array($meta) && isset($meta['cache_key']) && is_string($meta['cache_key']) ? $meta['cache_key'] : null;
        $storedAt = is_array($meta) && isset($meta['stored_at']) && is_int($meta['stored_at']) ? $meta['stored_at'] : null;

        $exp = $decoded['exp'] ?? null;
        $expTs = is_int($exp) && $exp > 0 ? $exp : null;
        $expired = $expTs !== null && $expTs < time();

        $headers = $response['headers'] ?? [];
        $ct = self::headerLine(is_array($headers) ? $headers : [], 'Content-Type');
        $bodyLen = strlen((string) ($response['body'] ?? ''));
        $fileBytes = (int) filesize($file);
        $mtime = (int) filemtime($file);

        $parsed = $cacheKey !== null ? self::parsePublicCacheKey($cacheKey) : [];
        $ctShort = $ct !== '' ? (strlen($ct) > 48 ? substr($ct, 0, 48) . '…' : $ct) : '';

        return [
            'file_id' => substr(pathinfo($file, PATHINFO_FILENAME), 0, 12) . '…',
            'file_bytes' => $fileBytes,
            'file_bytes_h' => self::formatBytes($fileBytes),
            'file_mtime_h' => self::formatTime($mtime),
            'expires_h' => $expTs !== null ? self::formatTime($expTs) : '—',
            'expired' => $expired,
            'legacy' => $cacheKey === null,
            'url_h' => self::buildUrlLabel($parsed, $cacheKey),
            'path_h' => $parsed['path'] ?? '—',
            'query_h' => ($parsed['query'] ?? '') !== '' ? $parsed['query'] : '—',
            'scheme_host_h' => self::schemeHostLabel($parsed),
            'status' => (int) ($response['status'] ?? 0),
            'content_type_h' => $ctShort !== '' ? $ctShort : '—',
            'body_bytes' => $bodyLen,
            'body_bytes_h' => self::formatBytes($bodyLen),
            'stored_at_h' => $storedAt !== null ? self::formatTime($storedAt) : '—',
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function inspectInternalFile(string $file): ?array
    {
        $raw = @file_get_contents($file);
        if ($raw === false || $raw === '') {
            return null;
        }
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($decoded) || !array_key_exists('v', $decoded)) {
            return null;
        }
        $v = $decoded['v'];
        if (!is_array($v)) {
            return null;
        }

        $exp = $decoded['exp'] ?? null;
        $expTs = is_int($exp) && $exp > 0 ? $exp : null;
        $expired = $expTs !== null && $expTs < time();

        $fileBytes = (int) filesize($file);
        $mtime = (int) filemtime($file);
        $jsonFlags = JSON_UNESCAPED_UNICODE;
        if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
            $jsonFlags |= JSON_INVALID_UTF8_SUBSTITUTE;
        }
        try {
            $payloadBytes = strlen((string) json_encode($v, $jsonFlags | JSON_THROW_ON_ERROR));
        } catch (\JsonException) {
            $payloadBytes = $fileBytes;
        }

        return [
            'file_id' => substr(pathinfo($file, PATHINFO_FILENAME), 0, 12) . '…',
            'file_bytes' => $fileBytes,
            'file_bytes_h' => self::formatBytes($fileBytes),
            'payload_bytes_h' => self::formatBytes($payloadBytes),
            'file_mtime_h' => self::formatTime($mtime),
            'expires_h' => $expTs !== null ? self::formatTime($expTs) : '—',
            'expired' => $expired,
            'label_h' => self::guessInternalLabel($v),
            'detail_h' => self::internalDetail($v),
        ];
    }

    /**
     * @param array<string, list<string>> $headers
     */
    private static function headerLine(array $headers, string $name): string
    {
        foreach ($headers as $k => $vals) {
            if (strcasecmp((string) $k, $name) === 0 && is_array($vals) && $vals !== []) {
                $first = $vals[0];

                return is_string($first) ? $first : '';
            }
        }

        return '';
    }

    /**
     * @return array{scheme?: string, host_port?: string, path?: string, query?: string}
     */
    private static function parsePublicCacheKey(string $key): array
    {
        $parts = explode('|', $key, 5);
        if (count($parts) < 4) {
            return [];
        }

        return [
            'scheme' => $parts[1] ?? '',
            'host_port' => $parts[2] ?? '',
            'path' => $parts[3] ?? '',
            'query' => $parts[4] ?? '',
        ];
    }

    /**
     * @param array{scheme?: string, host_port?: string, path?: string, query?: string} $parsed
     */
    private static function schemeHostLabel(array $parsed): string
    {
        $s = $parsed['scheme'] ?? '';
        $h = $parsed['host_port'] ?? '';
        if ($s === '' && $h === '') {
            return '—';
        }

        return $s !== '' && $h !== '' ? $s . '://' . $h : ($h !== '' ? $h : $s);
    }

    /**
     * @param array{scheme?: string, host_port?: string, path?: string, query?: string} $parsed
     */
    private static function buildUrlLabel(array $parsed, ?string $fullKey): string
    {
        if ($parsed === []) {
            return $fullKey !== null ? $fullKey : 'Legacy entry (no URL metadata)';
        }
        $path = $parsed['path'] ?? '/';
        $q = $parsed['query'] ?? '';

        return $path . ($q !== '' ? '?' . $q : '');
    }

    private static function guessInternalLabel(array $v): string
    {
        if (isset($v['m'], $v['s']) && is_array($v['m']) && is_array($v['s'])) {
            return 'Active theme (manifest + settings)';
        }
        if (isset($v['site_name'])) {
            return 'Site settings + branding (Twig)';
        }
        if ($v !== [] && array_is_list($v) && isset($v[0]) && is_array($v[0]) && isset($v[0]['href'], $v[0]['label'])) {
            return 'Menu tree (header or footer)';
        }

        return 'Twig globals blob';
    }

    private static function internalDetail(array $v): string
    {
        if (isset($v['m']) && is_array($v['m']) && isset($v['m']['slug']) && is_string($v['m']['slug'])) {
            return 'Theme slug: ' . $v['m']['slug'];
        }
        if (isset($v['site_name']) && is_string($v['site_name'])) {
            $sn = $v['site_name'];

            return 'site_name: ' . (strlen($sn) > 40 ? substr($sn, 0, 40) . '…' : $sn);
        }
        if ($v !== [] && array_is_list($v) && isset($v[0]) && is_array($v[0])) {
            $n = count($v);

            return $n . ' item(s)';
        }

        $keys = array_keys($v);

        return $keys === [] ? '' : implode(', ', array_slice(array_map('strval', $keys), 0, 6));
    }

    private static function formatBytes(int $bytes): string
    {
        if ($bytes >= 1_048_576) {
            return round($bytes / 1_048_576, 2) . ' MB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024, 1) . ' KB';
        }

        return (string) $bytes . ' B';
    }

    private static function formatTime(int $ts): string
    {
        return gmdate('Y-m-d H:i', $ts) . ' UTC';
    }
}
