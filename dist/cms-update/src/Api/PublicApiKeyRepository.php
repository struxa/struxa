<?php

declare(strict_types=1);

namespace App\Api;

use PDO;

final class PublicApiKeyRepository
{
    private const TABLE = 'cms_public_api_keys';

    private const ALLOWED_SCOPES = ['read', 'read_drafts', 'write'];

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function hasAnyActive(): bool
    {
        $stmt = $this->pdo->query('SELECT 1 FROM ' . self::TABLE . ' WHERE revoked_at IS NULL LIMIT 1');

        return (bool) $stmt->fetchColumn();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listAll(): array
    {
        $stmt = $this->pdo->query(
            'SELECT id, name, prefix, scopes_json, created_at, last_used_at, revoked_at FROM '
            . self::TABLE . ' ORDER BY id DESC'
        );
        $out = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $out[] = $row;
        }

        return $out;
    }

    /**
     * @param list<string> $scopes
     * @return array{id: int, prefix: string, secret_once: string}
     */
    public function create(string $name, array $scopes): array
    {
        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException('Name is required.');
        }
        $scopes = self::normalizeScopes($scopes);
        if ($scopes === []) {
            throw new \InvalidArgumentException('Select at least the read scope.');
        }
        $prefix = substr(bin2hex(random_bytes(8)), 0, 12);
        $secret = bin2hex(random_bytes(16));
        $full = $prefix . '.' . $secret;
        $hash = password_hash($full, PASSWORD_DEFAULT);
        if ($hash === false) {
            throw new \RuntimeException('Could not hash API key.');
        }
        $scopesJson = json_encode($scopes, JSON_THROW_ON_ERROR);
        $stmt = $this->pdo->prepare(
            'INSERT INTO ' . self::TABLE . ' (name, prefix, key_hash, scopes_json) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$name, $prefix, $hash, $scopesJson]);
        $id = (int) $this->pdo->lastInsertId();

        return ['id' => $id, 'prefix' => $prefix, 'secret_once' => $full];
    }

    /**
     * @return ?array{id: int, key_hash: string, scopes_json: string}
     */
    public function findActiveByPrefix(string $prefix): ?array
    {
        $prefix = trim($prefix);
        if ($prefix === '') {
            return null;
        }
        $stmt = $this->pdo->prepare(
            'SELECT id, key_hash, scopes_json FROM ' . self::TABLE
            . ' WHERE prefix = ? AND revoked_at IS NULL LIMIT 1'
        );
        $stmt->execute([$prefix]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : [
            'id' => (int) $row['id'],
            'key_hash' => (string) $row['key_hash'],
            'scopes_json' => (string) $row['scopes_json'],
        ];
    }

    public function touchLastUsed(int $id): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE ' . self::TABLE . ' SET last_used_at = CURRENT_TIMESTAMP WHERE id = ? AND revoked_at IS NULL'
        );
        $stmt->execute([$id]);
    }

    public function revoke(int $id): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE ' . self::TABLE . ' SET revoked_at = CURRENT_TIMESTAMP WHERE id = ? AND revoked_at IS NULL'
        );
        $stmt->execute([$id]);
    }

    /**
     * @param list<string> $scopes
     * @return list<string>
     */
    public static function normalizeScopes(array $scopes): array
    {
        $out = [];
        foreach ($scopes as $s) {
            $s = is_string($s) ? trim($s) : '';
            if ($s !== '' && in_array($s, self::ALLOWED_SCOPES, true)) {
                $out[$s] = true;
            }
        }
        $keys = array_keys($out);
        sort($keys);

        return $keys;
    }

    /**
     * @return list<string>
     */
    public static function decodeScopesJson(string $json): array
    {
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return ['read'];
        }
        if (!is_array($decoded)) {
            return ['read'];
        }

        return self::normalizeScopes(array_map('strval', $decoded));
    }
}
