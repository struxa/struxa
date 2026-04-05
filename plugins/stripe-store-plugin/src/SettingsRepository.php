<?php

declare(strict_types=1);

namespace StripeStorePlugin;

use PDO;

final class SettingsRepository
{
    private const TABLE = 'cms_plugin_stripe_store_settings';

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function tableExists(): bool
    {
        $stmt = $this->pdo->query(
            "SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = '" . self::TABLE . "' LIMIT 1"
        );

        return (bool) $stmt->fetchColumn();
    }

    /**
     * @return array{
     *   publishable_key: string,
     *   secret_key: string,
     *   webhook_secret: string,
     *   allowed_type_slugs: string,
     *   currency: string,
     *   embed_enabled: bool,
     *   button_label: string
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

        return [
            'publishable_key' => trim((string) ($row['publishable_key'] ?? '')),
            'secret_key' => trim((string) ($row['secret_key'] ?? '')),
            'webhook_secret' => trim((string) ($row['webhook_secret'] ?? '')),
            'allowed_type_slugs' => trim((string) ($row['allowed_type_slugs'] ?? 'products')) ?: 'products',
            'currency' => strtolower(trim((string) ($row['currency'] ?? 'usd'))) ?: 'usd',
            'embed_enabled' => (int) ($row['embed_enabled'] ?? 1) === 1,
            'button_label' => trim((string) ($row['button_label'] ?? 'Buy now')) ?: 'Buy now',
        ];
    }

    /**
     * @param array{
     *   publishable_key?: string,
     *   secret_key?: string,
     *   webhook_secret?: string,
     *   allowed_type_slugs?: string,
     *   currency?: string,
     *   embed_enabled?: bool|int|string,
     *   button_label?: string
     * } $data
     */
    public function save(array $data): void
    {
        $cur = $this->get();
        if (isset($data['secret_key']) && trim((string) $data['secret_key']) === '') {
            unset($data['secret_key']);
        }
        if (isset($data['webhook_secret']) && trim((string) $data['webhook_secret']) === '') {
            unset($data['webhook_secret']);
        }
        $merged = array_merge($cur, $data);
        $embed = $merged['embed_enabled'];
        if (is_bool($embed)) {
            $embed = $embed ? 1 : 0;
        } elseif (is_string($embed)) {
            $embed = ($embed === '1' || strtolower($embed) === 'true') ? 1 : 0;
        } else {
            $embed = $embed ? 1 : 0;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO ' . self::TABLE . ' (id, publishable_key, secret_key, webhook_secret, allowed_type_slugs, currency, embed_enabled, button_label)
             VALUES (1, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
               publishable_key = VALUES(publishable_key),
               secret_key = VALUES(secret_key),
               webhook_secret = VALUES(webhook_secret),
               allowed_type_slugs = VALUES(allowed_type_slugs),
               currency = VALUES(currency),
               embed_enabled = VALUES(embed_enabled),
               button_label = VALUES(button_label)'
        );
        $stmt->execute([
            $merged['publishable_key'] !== '' ? $merged['publishable_key'] : null,
            $merged['secret_key'] !== '' ? $merged['secret_key'] : null,
            $merged['webhook_secret'] !== '' ? $merged['webhook_secret'] : null,
            $merged['allowed_type_slugs'],
            $merged['currency'],
            $embed,
            $merged['button_label'],
        ]);
    }

    public function effectiveSecretKey(): string
    {
        $env = trim((string) ($_ENV['STRIPE_SECRET_KEY'] ?? getenv('STRIPE_SECRET_KEY') ?: ''));
        if ($env !== '') {
            return $env;
        }

        return $this->get()['secret_key'];
    }

    public function effectiveWebhookSecret(): string
    {
        $env = trim((string) ($_ENV['STRIPE_WEBHOOK_SECRET'] ?? getenv('STRIPE_WEBHOOK_SECRET') ?: ''));
        if ($env !== '') {
            return $env;
        }

        return $this->get()['webhook_secret'];
    }

    /**
     * @return list<string>
     */
    public function allowedTypeSlugList(): array
    {
        $raw = $this->get()['allowed_type_slugs'];
        $parts = preg_split('/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY);

        return $parts === false ? [] : array_values(array_unique(array_map('strtolower', $parts)));
    }

    /**
     * @return array<string, mixed>
     */
    private function defaults(): array
    {
        return [
            'publishable_key' => '',
            'secret_key' => '',
            'webhook_secret' => '',
            'allowed_type_slugs' => 'products',
            'currency' => 'usd',
            'embed_enabled' => true,
            'button_label' => 'Buy now',
        ];
    }
}
