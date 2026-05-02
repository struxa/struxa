<?php

declare(strict_types=1);

namespace ContentStreamPlugin;

use PDO;

final class SettingsRepository
{
    private const TABLE = 'cms_plugin_content_stream_settings';

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function tableExists(): bool
    {
        try {
            $this->pdo->query('SELECT 1 FROM ' . self::TABLE . ' LIMIT 1');

            return true;
        } catch (\PDOException) {
            return false;
        }
    }

    /**
     * @return array{
     *   openai_api_key: string,
     *   openai_organization: string,
     *   openai_model: string,
     *   api_key_stored: bool,
     *   dataforseo_login: string,
     *   dataforseo_password: string,
     *   dataforseo_configured: bool,
     *   keyword_location_code: int,
     *   keyword_language_code: string
     * }
     */
    public function get(): array
    {
        if (!$this->tableExists()) {
            return $this->defaults();
        }
        $stmt = $this->pdo->query('SELECT * FROM ' . self::TABLE . ' WHERE id = 1 LIMIT 1');
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return $this->defaults();
        }
        $key = trim((string) ($row['openai_api_key'] ?? ''));
        $dfsLogin = trim((string) ($row['dataforseo_login'] ?? ''));
        $dfsPass = trim((string) ($row['dataforseo_password'] ?? ''));
        $loc = $row['keyword_location_code'] ?? null;
        $keywordLocation = is_numeric($loc) ? max(1, (int) $loc) : 2840;
        $lang = trim((string) ($row['keyword_language_code'] ?? 'en'));
        if ($lang === '' || strlen($lang) > 8) {
            $lang = 'en';
        }

        return [
            'openai_api_key' => $key,
            'openai_organization' => trim((string) ($row['openai_organization'] ?? '')),
            'openai_model' => trim((string) ($row['openai_model'] ?? '')) ?: 'gpt-4o-mini',
            'api_key_stored' => $key !== '',
            'dataforseo_login' => $dfsLogin,
            'dataforseo_password' => $dfsPass,
            'dataforseo_configured' => $dfsLogin !== '' && $dfsPass !== '',
            'keyword_location_code' => $keywordLocation,
            'keyword_language_code' => $lang,
        ];
    }

    /**
     * @param array{
     *   openai_api_key?: string,
     *   openai_organization?: string,
     *   openai_model?: string,
     *   dataforseo_login?: string,
     *   dataforseo_password?: string,
     *   keyword_location_code?: int,
     *   keyword_language_code?: string
     * } $data
     */
    public function save(array $data): void
    {
        $cur = $this->get();
        if (isset($data['openai_api_key']) && trim((string) $data['openai_api_key']) === '') {
            unset($data['openai_api_key']);
        }
        if (isset($data['dataforseo_password']) && trim((string) $data['dataforseo_password']) === '') {
            unset($data['dataforseo_password']);
        }
        $merged = array_merge(
            [
                'openai_api_key' => $cur['openai_api_key'],
                'openai_organization' => $cur['openai_organization'],
                'openai_model' => $cur['openai_model'],
                'dataforseo_login' => $cur['dataforseo_login'],
                'dataforseo_password' => $cur['dataforseo_password'],
                'keyword_location_code' => $cur['keyword_location_code'],
                'keyword_language_code' => $cur['keyword_language_code'],
            ],
            $data
        );
        $model = trim($merged['openai_model']) ?: 'gpt-4o-mini';
        if (strlen($model) > 80) {
            $model = 'gpt-4o-mini';
        }
        $org = trim($merged['openai_organization']);
        if (strlen($org) > 120) {
            $org = '';
        }
        $dfsLogin = trim((string) $merged['dataforseo_login']);
        if (strlen($dfsLogin) > 255) {
            $dfsLogin = substr($dfsLogin, 0, 255);
        }
        $dfsPass = trim((string) $merged['dataforseo_password']);
        if (strlen($dfsPass) > 512) {
            $dfsPass = substr($dfsPass, 0, 512);
        }
        $loc = (int) $merged['keyword_location_code'];
        if ($loc < 1) {
            $loc = 2840;
        }
        $lang = trim((string) $merged['keyword_language_code']);
        if ($lang === '' || strlen($lang) > 8) {
            $lang = 'en';
        }

        if ($this->hasDataForSeoColumns()) {
            $stmt = $this->pdo->prepare(
                'INSERT INTO ' . self::TABLE . ' (id, openai_api_key, openai_organization, openai_model, dataforseo_login, dataforseo_password, keyword_location_code, keyword_language_code)
                 VALUES (1, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE openai_api_key = VALUES(openai_api_key), openai_organization = VALUES(openai_organization), openai_model = VALUES(openai_model), dataforseo_login = VALUES(dataforseo_login), dataforseo_password = VALUES(dataforseo_password), keyword_location_code = VALUES(keyword_location_code), keyword_language_code = VALUES(keyword_language_code)'
            );
            $stmt->execute([$merged['openai_api_key'], $org, $model, $dfsLogin !== '' ? $dfsLogin : null, $dfsPass !== '' ? $dfsPass : null, $loc, $lang]);
        } else {
            $stmt = $this->pdo->prepare(
                'INSERT INTO ' . self::TABLE . ' (id, openai_api_key, openai_organization, openai_model)
                 VALUES (1, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE openai_api_key = VALUES(openai_api_key), openai_organization = VALUES(openai_organization), openai_model = VALUES(openai_model)'
            );
            $stmt->execute([$merged['openai_api_key'], $org, $model]);
        }
    }

    public function clearApiKey(): void
    {
        if (!$this->tableExists()) {
            return;
        }
        $stmt = $this->pdo->prepare('UPDATE ' . self::TABLE . ' SET openai_api_key = NULL WHERE id = 1');
        $stmt->execute();
    }

    public function clearDataForSeoPassword(): void
    {
        if (!$this->tableExists() || !$this->hasDataForSeoColumns()) {
            return;
        }
        $stmt = $this->pdo->prepare('UPDATE ' . self::TABLE . ' SET dataforseo_password = NULL WHERE id = 1');
        $stmt->execute();
    }

    private function hasDataForSeoColumns(): bool
    {
        try {
            $stmt = $this->pdo->query('SHOW COLUMNS FROM ' . self::TABLE . " LIKE 'dataforseo_login'");

            return $stmt !== false && $stmt->fetch(PDO::FETCH_ASSOC) !== false;
        } catch (\PDOException) {
            return false;
        }
    }

    /**
     * @return array{
     *   openai_api_key: string,
     *   openai_organization: string,
     *   openai_model: string,
     *   api_key_stored: bool,
     *   dataforseo_login: string,
     *   dataforseo_password: string,
     *   dataforseo_configured: bool,
     *   keyword_location_code: int,
     *   keyword_language_code: string
     * }
     */
    private function defaults(): array
    {
        return [
            'openai_api_key' => '',
            'openai_organization' => '',
            'openai_model' => 'gpt-4o-mini',
            'api_key_stored' => false,
            'dataforseo_login' => '',
            'dataforseo_password' => '',
            'dataforseo_configured' => false,
            'keyword_location_code' => 2840,
            'keyword_language_code' => 'en',
        ];
    }
}
