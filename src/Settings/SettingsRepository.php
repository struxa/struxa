<?php

declare(strict_types=1);

namespace App\Settings;

use PDO;

final class SettingsRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array<string, string>
     */
    public function allKeyValues(): array
    {
        $stmt = $this->pdo->query('SELECT setting_key, setting_value FROM cms_settings ORDER BY setting_key ASC');
        $out = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $k = (string) ($row['setting_key'] ?? '');
            $out[$k] = (string) ($row['setting_value'] ?? '');
        }

        return $out;
    }

    public function upsert(string $key, string $value, bool $autoload = true): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO cms_settings (setting_key, setting_value, autoload) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), autoload = VALUES(autoload)'
        );
        $stmt->execute([$key, $value, $autoload ? 1 : 0]);
    }

    /**
     * @param array<string, string> $pairs
     */
    public function upsertMany(array $pairs, bool $autoload = true): void
    {
        foreach ($pairs as $key => $value) {
            $this->upsert((string) $key, $value, $autoload);
        }
    }
}
