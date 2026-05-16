<?php

declare(strict_types=1);

namespace App\Dist;

/**
 * Fetches the public Struxa distribution catalog (themes + plugins) from HTTPS or local storage.
 */
final class StruxaDistCatalogClient
{
    public const DEFAULT_CATALOG_URL = 'https://struxapoint.com/struxa-dist/repo.json';

    private const MAX_BYTES = 512_000;

    public function __construct(
        private readonly string $projectRoot,
    ) {
    }

    public function resolveCatalogUrl(): string
    {
        foreach (['STRUXA_DIST_CATALOG_URL', 'STRUXA_THEME_CATALOG_URL', 'STRUXA_PLUGIN_CATALOG_URL'] as $key) {
            $v = trim((string) ($_ENV[$key] ?? getenv($key) ?: ''));
            if ($v !== '') {
                return $v;
            }
        }

        return self::DEFAULT_CATALOG_URL;
    }

    /**
     * @return array{ok: true, data: array<string, mixed>}|array{ok: false, error: string}
     */
    public function loadCatalog(): array
    {
        $url = $this->resolveCatalogUrl();
        if ($url !== '' && !str_starts_with($url, 'https://')) {
            return ['ok' => false, 'error' => 'Catalog URL must use https:// (STRUXA_DIST_CATALOG_URL or STRUXA_THEME_CATALOG_URL).'];
        }

        $json = null;
        if ($url !== '') {
            $json = $this->httpGetLimited($url, self::MAX_BYTES);
        }
        if ($json === null || $json === '') {
            $json = $this->readPublishedCatalogFromDisk();
        }
        if ($json === null || $json === '') {
            $json = $this->readLocalFallback();
        }
        if ($json === null || $json === '') {
            return [
                'ok' => false,
                'error' => 'Could not load the catalog from ' . ($url !== '' ? $url : self::DEFAULT_CATALOG_URL)
                    . '. Host repo.json on HTTPS or add storage/dist-catalog.json (see storage/dist-catalog.example.json).',
            ];
        }

        try {
            /** @var mixed $data */
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return ['ok' => false, 'error' => 'Distribution catalog is not valid JSON.'];
        }
        if (!is_array($data)) {
            return ['ok' => false, 'error' => 'Distribution catalog must be a JSON object.'];
        }

        return ['ok' => true, 'data' => $data];
    }

    /**
     * When the CMS and catalog share a host, HTTP self-fetch often fails; read the published file instead.
     */
    private function readPublishedCatalogFromDisk(): ?string
    {
        foreach ([
            $this->projectRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'struxa-dist' . DIRECTORY_SEPARATOR . 'repo.json',
            $this->projectRoot . DIRECTORY_SEPARATOR . 'struxa-dist' . DIRECTORY_SEPARATOR . 'repo.json',
        ] as $path) {
            if (!is_readable($path)) {
                continue;
            }
            $loaded = file_get_contents($path);
            if ($loaded !== false && $loaded !== '') {
                return $loaded;
            }
        }

        return null;
    }

    private function readLocalFallback(): ?string
    {
        foreach (['dist-catalog.json', 'theme-catalog.json'] as $name) {
            $path = $this->projectRoot . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . $name;
            if (!is_readable($path)) {
                continue;
            }
            $loaded = file_get_contents($path);
            if ($loaded === false || $loaded === '') {
                continue;
            }
            if ($name === 'theme-catalog.json') {
                try {
                    $data = json_decode($loaded, true, 512, JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    continue;
                }
                if (is_array($data) && isset($data['themes']) && !isset($data['plugins'])) {
                    $data['plugins'] = [];

                    return json_encode($data, JSON_THROW_ON_ERROR);
                }
            }

            return $loaded;
        }

        return null;
    }

    private function httpGetLimited(string $url, int $maxBytes): ?string
    {
        $ctx = stream_context_create([
            'http' => [
                'timeout' => 20,
                'follow_location' => 1,
                'max_redirects' => 5,
                'header' => "User-Agent: Struxa-DistCatalog/1.0\r\nAccept: application/json\r\n",
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
            $chunk = fread($h, 8192);
            if ($chunk === false) {
                break;
            }
            $data .= $chunk;
        }
        fclose($h);
        if (strlen($data) >= $maxBytes || $data === '') {
            return null;
        }

        return $data;
    }
}
