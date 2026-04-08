<?php

declare(strict_types=1);

namespace App\Security;

use PDO;
use PDOException;

final class IpBlockRepository
{
    public const CACHE_KEY = 'ip_block:patterns_v1';

    public function __construct(
        private readonly PDO $pdo,
    ) {
    }

    /**
     * @return list<array{id: int, pattern: string, note: ?string, created_at: string}>
     */
    public function listRows(): array
    {
        try {
            $st = $this->pdo->query(
                'SELECT id, pattern, note, created_at FROM cms_ip_blocks ORDER BY id ASC'
            );
            if ($st === false) {
                return [];
            }
            $out = [];
            while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                if (!is_array($row)) {
                    continue;
                }
                $id = (int) ($row['id'] ?? 0);
                if ($id < 1) {
                    continue;
                }
                $note = $row['note'] ?? null;
                $out[] = [
                    'id' => $id,
                    'pattern' => (string) ($row['pattern'] ?? ''),
                    'note' => $note !== null && trim((string) $note) !== '' ? (string) $note : null,
                    'created_at' => (string) ($row['created_at'] ?? ''),
                ];
            }

            return $out;
        } catch (PDOException) {
            return [];
        }
    }

    /**
     * @return list<string>
     */
    public function allPatterns(): array
    {
        try {
            $st = $this->pdo->query('SELECT pattern FROM cms_ip_blocks ORDER BY id ASC');
            if ($st === false) {
                return [];
            }
            $out = [];
            while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                if (!is_array($row)) {
                    continue;
                }
                $p = trim((string) ($row['pattern'] ?? ''));
                if ($p !== '') {
                    $out[] = $p;
                }
            }

            return $out;
        } catch (PDOException) {
            return [];
        }
    }

    /**
     * @return array{ok: true, id: int}|array{ok: false, duplicate: true}
     */
    public function insert(string $pattern, ?string $note): array
    {
        $noteVal = null;
        if ($note !== null) {
            $t = trim($note);
            if ($t !== '') {
                $noteVal = function_exists('mb_substr') ? mb_substr($t, 0, 255) : substr($t, 0, 255);
            }
        }
        try {
            $st = $this->pdo->prepare('INSERT INTO cms_ip_blocks (pattern, note) VALUES (:p, :n)');
            $st->execute([':p' => $pattern, ':n' => $noteVal]);

            return ['ok' => true, 'id' => (int) $this->pdo->lastInsertId()];
        } catch (PDOException $e) {
            $state = isset($e->errorInfo[0]) && is_string($e->errorInfo[0]) ? $e->errorInfo[0] : '';
            if ($state === '23000' || str_contains($e->getMessage(), 'Duplicate')) {
                return ['ok' => false, 'duplicate' => true];
            }
            throw $e;
        }
    }

    public function deleteById(int $id): bool
    {
        if ($id < 1) {
            return false;
        }
        try {
            $st = $this->pdo->prepare('DELETE FROM cms_ip_blocks WHERE id = :id LIMIT 1');
            $st->execute([':id' => $id]);

            return $st->rowCount() > 0;
        } catch (PDOException) {
            return false;
        }
    }
}
