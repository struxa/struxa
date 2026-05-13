<?php

declare(strict_types=1);

namespace App\Preview;

use PDO;

final class PreviewTokenRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return string Plain token (show once to user); only SHA-256 hash is stored.
     */
    public function mint(string $subjectType, int $subjectId, int $ttlSeconds, ?int $createdBy): string
    {
        if ($subjectType !== 'page' && $subjectType !== 'content_entry') {
            throw new \InvalidArgumentException('subjectType');
        }
        if ($subjectId < 1) {
            throw new \InvalidArgumentException('subjectId');
        }
        $ttlSeconds = max(300, min(86400 * 30, $ttlSeconds));
        $plain = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $hash = hash('sha256', $plain);
        $exp = gmdate('Y-m-d H:i:s', time() + $ttlSeconds);
        $stmt = $this->pdo->prepare(
            'INSERT INTO cms_preview_tokens (token_hash, subject_type, subject_id, created_by, expires_at) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$hash, $subjectType, $subjectId, $createdBy, $exp]);

        return $plain;
    }

    /**
     * @return array{subject_type: string, subject_id: int}|null
     */
    public function verify(string $plainToken): ?array
    {
        $plainToken = trim($plainToken);
        if (strlen($plainToken) < 16) {
            return null;
        }
        $hash = hash('sha256', $plainToken);
        $stmt = $this->pdo->prepare(
            'SELECT subject_type, subject_id FROM cms_preview_tokens WHERE token_hash = ? AND expires_at > NOW() LIMIT 1'
        );
        $stmt->execute([$hash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        return [
            'subject_type' => (string) ($row['subject_type'] ?? ''),
            'subject_id' => (int) ($row['subject_id'] ?? 0),
        ];
    }

    public function deleteExpired(): int
    {
        return (int) $this->pdo->exec('DELETE FROM cms_preview_tokens WHERE expires_at < NOW()');
    }
}
