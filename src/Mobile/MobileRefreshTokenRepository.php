<?php

declare(strict_types=1);

namespace App\Mobile;

use PDO;

final class MobileRefreshTokenRepository
{
    public const TTL_DAYS = 30;

    public function __construct(private readonly PDO $pdo)
    {
    }

    public static function tableExists(PDO $pdo): bool
    {
        $stmt = $pdo->query("SHOW TABLES LIKE 'cms_mobile_refresh_tokens'");

        return $stmt !== false && $stmt->rowCount() > 0;
    }

    /**
     * @return array{id: int, token: string, expires_at: string}
     */
    public function create(int $phpauthUserId): array
    {
        $plain = bin2hex(random_bytes(32));
        $hash = hash('sha256', $plain);
        $expiresAt = gmdate('Y-m-d H:i:s', time() + self::TTL_DAYS * 86400);

        $stmt = $this->pdo->prepare(
            'INSERT INTO cms_mobile_refresh_tokens (phpauth_user_id, token_hash, expires_at) VALUES (?, ?, ?)'
        );
        $stmt->execute([$phpauthUserId, $hash, $expiresAt]);

        return [
            'id' => (int) $this->pdo->lastInsertId(),
            'token' => $plain,
            'expires_at' => $expiresAt,
        ];
    }

    /**
     * @return array{id: int, phpauth_user_id: int, expires_at: string}|null
     */
    public function findActiveByPlainToken(string $plainToken): ?array
    {
        $plainToken = trim($plainToken);
        if ($plainToken === '') {
            return null;
        }

        $hash = hash('sha256', $plainToken);
        $stmt = $this->pdo->prepare(
            'SELECT id, phpauth_user_id, expires_at
             FROM cms_mobile_refresh_tokens
             WHERE token_hash = ? AND revoked_at IS NULL AND expires_at > UTC_TIMESTAMP()
             LIMIT 1'
        );
        $stmt->execute([$hash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    public function revokeById(int $id): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE cms_mobile_refresh_tokens SET revoked_at = UTC_TIMESTAMP() WHERE id = ? AND revoked_at IS NULL'
        );
        $stmt->execute([$id]);
    }

    public function revokeByPlainToken(string $plainToken): void
    {
        $row = $this->findActiveByPlainToken($plainToken);
        if ($row !== null) {
            $this->revokeById((int) $row['id']);
        }
    }
}
