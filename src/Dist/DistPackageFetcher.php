<?php

declare(strict_types=1);

namespace App\Dist;

/**
 * Fetches distribution ZIPs from HTTPS or from local struxa-dist/zips/ when present.
 *
 * Avoids failed HTTP self-fetches when the CMS and catalog share a host (common on struxapoint.com
 * and local dev before ZIPs are deployed to production).
 */
final class DistPackageFetcher
{
    public function __construct(
        private readonly string $projectRoot,
    ) {
    }

    public function fetchZip(string $url, int $maxBytes): ?string
    {
        $local = $this->resolveLocalZipPath($url);
        if ($local !== null) {
            $size = filesize($local);
            if ($size !== false && $size > 0 && $size <= $maxBytes) {
                $data = file_get_contents($local);
                if ($data !== false && $data !== '') {
                    return $data;
                }
            }
        }

        return $this->httpGetLimited($url, $maxBytes);
    }

    /**
     * Path tried for admin messaging when fetch fails.
     */
    public function localZipHint(string $url): ?string
    {
        $local = $this->resolveLocalZipPath($url);

        return $local ?? $this->expectedLocalPath($url);
    }

    private function resolveLocalZipPath(string $url): ?string
    {
        $file = $this->zipBasenameFromUrl($url);
        if ($file === null) {
            return null;
        }

        foreach ([
            $this->projectRoot . '/public/struxa-dist/zips/' . $file,
            $this->projectRoot . '/struxa-dist/zips/' . $file,
        ] as $path) {
            if (is_file($path) && is_readable($path)) {
                return $path;
            }
        }

        return null;
    }

    private function expectedLocalPath(string $url): ?string
    {
        $file = $this->zipBasenameFromUrl($url);

        return $file !== null ? 'struxa-dist/zips/' . $file : null;
    }

    private function zipBasenameFromUrl(string $url): ?string
    {
        $path = parse_url($url, PHP_URL_PATH);
        if (!is_string($path) || !preg_match('#/struxa-dist/zips/([a-z0-9][a-z0-9\-]*\.zip)$#i', $path, $m)) {
            return null;
        }

        return strtolower($m[1]);
    }

    private function httpGetLimited(string $url, int $maxBytes): ?string
    {
        if (!str_starts_with($url, 'https://')) {
            return null;
        }

        $ua = 'Struxa-DistPackage/1.1 (+https://struxapoint.com)';

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch !== false) {
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_MAXREDIRS => 8,
                    CURLOPT_CONNECTTIMEOUT => 15,
                    CURLOPT_TIMEOUT => 120,
                    CURLOPT_HTTPHEADER => ['User-Agent: ' . $ua],
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_SSL_VERIFYHOST => 2,
                ]);
                $raw = curl_exec($ch);
                $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                if (is_string($raw) && $code >= 200 && $code < 300 && $raw !== '' && strlen($raw) < $maxBytes) {
                    return $raw;
                }
            }
        }

        $ctx = stream_context_create([
            'http' => [
                'timeout' => 120,
                'follow_location' => 1,
                'max_redirects' => 8,
                'header' => "User-Agent: {$ua}\r\n",
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);
        $h = @fopen($url, 'r', false, $ctx);
        if ($h === false) {
            return null;
        }
        $data = '';
        while (!feof($h) && strlen($data) < $maxBytes) {
            $chunk = fread($h, 65_536);
            if ($chunk === false) {
                break;
            }
            $data .= $chunk;
        }
        fclose($h);
        if ($data === '' || strlen($data) >= $maxBytes) {
            return null;
        }

        return $data;
    }
}
