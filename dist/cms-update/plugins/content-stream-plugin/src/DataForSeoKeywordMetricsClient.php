<?php

declare(strict_types=1);

namespace ContentStreamPlugin;

/**
 * Live keyword metrics via DataForSEO (Google Ads volume + Labs difficulty + related variations).
 */
final class DataForSeoKeywordMetricsClient
{
    private const BASE = 'https://api.dataforseo.com';

    public function __construct(
        private readonly string $login,
        private readonly string $password,
        private readonly int $locationCode,
        private readonly string $languageCode,
    ) {
    }

    /**
     * @param list<string> $keywords
     *
     * @return list<array{
     *   keyword: string,
     *   search_volume: int|null,
     *   cpc: float|null,
     *   competition: string|null,
     *   competition_index: int|null,
     *   difficulty: int|null,
     *   variations: list<string>
     * }>
     */
    public function buildRows(array $keywords): array
    {
        if ($keywords === []) {
            return [];
        }

        $volumeByLower = $this->searchVolumeLive($keywords);
        $diffByLower = $this->bulkKeywordDifficultyLive($keywords);

        $rows = [];
        foreach ($keywords as $kw) {
            $lk = strtolower($kw);
            $vol = $volumeByLower[$lk] ?? [];
            $rows[] = [
                'keyword' => $kw,
                'search_volume' => isset($vol['search_volume']) && is_numeric($vol['search_volume']) ? (int) $vol['search_volume'] : null,
                'cpc' => isset($vol['cpc']) && is_numeric($vol['cpc']) ? (float) $vol['cpc'] : null,
                'competition' => isset($vol['competition']) && is_string($vol['competition']) ? $vol['competition'] : null,
                'competition_index' => isset($vol['competition_index']) && is_numeric($vol['competition_index']) ? (int) $vol['competition_index'] : null,
                'difficulty' => $diffByLower[$lk] ?? null,
                'variations' => [],
            ];
        }

        foreach ($rows as $i => $row) {
            $rows[$i]['variations'] = $this->relatedKeywordVariations($row['keyword']);
            usleep(250_000);
        }

        return $rows;
    }

    /**
     * @param list<string> $keywords
     *
     * @return array<string, array<string, mixed>>
     */
    private function searchVolumeLive(array $keywords): array
    {
        $body = [[
            'location_code' => $this->locationCode,
            'language_code' => $this->languageCode,
            'keywords' => array_values($keywords),
        ]];

        $decoded = $this->postJson('/v3/keywords_data/google_ads/search_volume/live', $body);
        $this->assertTasksOk($decoded);

        $map = [];
        $tasks = $decoded['tasks'] ?? [];
        if (!is_array($tasks) || $tasks === []) {
            return $map;
        }
        $task = $tasks[0];
        $results = $task['result'] ?? [];
        if (!is_array($results)) {
            return $map;
        }
        foreach ($results as $item) {
            if (!is_array($item)) {
                continue;
            }
            $k = isset($item['keyword']) && is_string($item['keyword']) ? trim($item['keyword']) : '';
            if ($k === '') {
                continue;
            }
            $map[strtolower($k)] = $item;
        }

        return $map;
    }

    /**
     * @param list<string> $keywords
     *
     * @return array<string, int|null>
     */
    private function bulkKeywordDifficultyLive(array $keywords): array
    {
        $body = [[
            'location_code' => $this->locationCode,
            'language_code' => $this->languageCode,
            'keywords' => array_values($keywords),
        ]];

        $decoded = $this->postJson('/v3/dataforseo_labs/google/bulk_keyword_difficulty/live', $body);
        $this->assertTasksOk($decoded);

        $map = [];
        $tasks = $decoded['tasks'] ?? [];
        if (!is_array($tasks) || $tasks === []) {
            return $map;
        }
        $task = $tasks[0];
        $resultBlocks = $task['result'] ?? [];
        if (!is_array($resultBlocks) || $resultBlocks === []) {
            return $map;
        }
        $items = $resultBlocks[0]['items'] ?? [];
        if (!is_array($items)) {
            return $map;
        }
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $k = isset($item['keyword']) && is_string($item['keyword']) ? trim($item['keyword']) : '';
            if ($k === '') {
                continue;
            }
            $d = $item['keyword_difficulty'] ?? null;
            $map[strtolower($k)] = is_numeric($d) ? (int) $d : null;
        }

        return $map;
    }

    /**
     * @return list<string>
     */
    private function relatedKeywordVariations(string $seed): array
    {
        $seed = KeywordPhraseNormalizer::normalizeOne($seed);
        if ($seed === '') {
            return [];
        }

        $body = [[
            'keyword' => $seed,
            'location_code' => $this->locationCode,
            'language_code' => $this->languageCode,
            'depth' => 1,
            'limit' => 12,
        ]];

        try {
            $decoded = $this->postJson('/v3/dataforseo_labs/google/related_keywords/live', $body);
            $this->assertTasksOk($decoded);
        } catch (\Throwable) {
            return [];
        }

        $out = [];
        $seen = [strtolower($seed) => true];
        $tasks = $decoded['tasks'] ?? [];
        if (!is_array($tasks) || $tasks === []) {
            return [];
        }
        $task = $tasks[0];
        $resultBlocks = $task['result'] ?? [];
        if (!is_array($resultBlocks) || $resultBlocks === []) {
            return [];
        }
        $items = $resultBlocks[0]['items'] ?? [];
        if (!is_array($items)) {
            return [];
        }
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $kd = $item['keyword_data'] ?? null;
            if (!is_array($kd)) {
                continue;
            }
            $kw = isset($kd['keyword']) && is_string($kd['keyword']) ? trim($kd['keyword']) : '';
            if ($kw === '') {
                continue;
            }
            $lk = strtolower($kw);
            if (isset($seen[$lk])) {
                continue;
            }
            $seen[$lk] = true;
            if (strcasecmp($kw, $seed) === 0) {
                continue;
            }
            $out[] = $kw;
            if (count($out) >= 8) {
                break;
            }
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>>|array<int, array<string, mixed>> $body
     *
     * @return array<string, mixed>
     */
    private function postJson(string $path, array $body): array
    {
        $auth = base64_encode($this->login . ':' . $this->password);
        $json = json_encode($body, JSON_THROW_ON_ERROR);
        $ctx = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\nAuthorization: Basic {$auth}",
                'content' => $json,
                'timeout' => 120,
                'ignore_errors' => true,
            ],
        ]);

        $url = self::BASE . $path;
        $raw = @file_get_contents($url, false, $ctx);
        if ($raw === false) {
            throw new \RuntimeException('Could not reach the DataForSEO API.');
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Unexpected response from DataForSEO.');
        }

        $code = (int) ($decoded['status_code'] ?? 0);
        if ($code !== 20000) {
            $msg = isset($decoded['status_message']) && is_string($decoded['status_message'])
                ? $decoded['status_message']
                : 'Request failed.';
            throw new \RuntimeException('DataForSEO: ' . $msg);
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $decoded
     */
    private function assertTasksOk(array $decoded): void
    {
        $tasks = $decoded['tasks'] ?? [];
        if (!is_array($tasks) || $tasks === []) {
            throw new \RuntimeException('DataForSEO returned no task data.');
        }
        $t = $tasks[0];
        $tc = (int) ($t['status_code'] ?? 0);
        if ($tc !== 20000) {
            $m = isset($t['status_message']) && is_string($t['status_message']) ? $t['status_message'] : 'task error';

            throw new \RuntimeException('DataForSEO: ' . $m);
        }
    }
}
