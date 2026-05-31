<?php

declare(strict_types=1);

namespace App\Security;

use PDO;

final class SystemApiKeyRepository
{
    public function __construct(
        private readonly PDO $pdo,
    ) {
    }

    /**
     * @return list<array{id:int, provider:string, key_name:string, key_value:string, created_at:string, updated_at:string}>
     */
    public function listByProvider(string $provider): array
    {
        $provider = trim($provider);
        if ($provider === '') {
            return [];
        }
        $st = $this->pdo->prepare(
            'SELECT id, provider, key_name, key_value, created_at, updated_at
             FROM cms_system_api_keys
             WHERE provider = :provider
             ORDER BY key_name ASC'
        );
        $st->execute([':provider' => $provider]);

        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function upsert(string $provider, string $name, string $value): int
    {
        $provider = trim($provider);
        $name = trim($name);
        if ($provider === '' || $name === '' || $value === '') {
            throw new \InvalidArgumentException('Provider, name, and key are required.');
        }
        if (strlen($provider) > 60 || strlen($name) > 160 || strlen($value) > 1024) {
            throw new \InvalidArgumentException('API key data is too long.');
        }

        $st = $this->pdo->prepare(
            'INSERT INTO cms_system_api_keys (provider, key_name, key_value)
             VALUES (:provider, :key_name, :key_value)
             ON DUPLICATE KEY UPDATE key_value = VALUES(key_value), updated_at = CURRENT_TIMESTAMP'
        );
        $st->execute([':provider' => $provider, ':key_name' => $name, ':key_value' => $value]);

        $idSt = $this->pdo->prepare(
            'SELECT id FROM cms_system_api_keys WHERE provider = :provider AND key_name = :key_name LIMIT 1'
        );
        $idSt->execute([':provider' => $provider, ':key_name' => $name]);
        $row = $idSt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? (int) ($row['id'] ?? 0) : 0;
    }

    public function findById(int $id): ?array
    {
        if ($id < 1) {
            return null;
        }
        $st = $this->pdo->prepare(
            'SELECT id, provider, key_name, key_value, created_at, updated_at
             FROM cms_system_api_keys WHERE id = :id LIMIT 1'
        );
        $st->execute([':id' => $id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    public function deleteById(int $id): bool
    {
        if ($id < 1) {
            return false;
        }
        $st = $this->pdo->prepare('DELETE FROM cms_system_api_keys WHERE id = :id LIMIT 1');
        $st->execute([':id' => $id]);

        return $st->rowCount() > 0;
    }

    public function deleteByProviderAndName(string $provider, string $name): bool
    {
        $provider = trim($provider);
        $name = trim($name);
        if ($provider === '' || $name === '') {
            return false;
        }
        $st = $this->pdo->prepare(
            'DELETE FROM cms_system_api_keys WHERE provider = :provider AND key_name = :key_name LIMIT 1'
        );
        $st->execute([':provider' => $provider, ':key_name' => $name]);

        return $st->rowCount() > 0;
    }
}
