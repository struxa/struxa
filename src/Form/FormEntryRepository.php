<?php

declare(strict_types=1);

namespace App\Form;

use PDO;

final class FormEntryRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listForForm(int $formId, string $status = 'all', int $limit = 300): array
    {
        $sql = 'SELECT * FROM cms_form_entries WHERE form_id = ?';
        $params = [$formId];
        if ($status !== 'all') {
            $sql .= ' AND status = ?';
            $params[] = $status;
        }
        $sql .= ' ORDER BY created_at DESC, id DESC LIMIT ' . max(1, min(500, $limit));
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function countByStatus(int $formId, string $status): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM cms_form_entries WHERE form_id = ? AND status = ?'
        );
        $stmt->execute([$formId, $status]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $entryId, int $formId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM cms_form_entries WHERE id = ? AND form_id = ? LIMIT 1'
        );
        $stmt->execute([$entryId, $formId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function valuesForEntry(int $entryId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM cms_form_entry_values WHERE entry_id = ? ORDER BY id ASC'
        );
        $stmt->execute([$entryId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @param list<array{field_id: int|null, field_key: string, value_text: string|null, value_file_path?: string|null}> $values
     * @param array{score?: int, max_score?: int, passed?: bool}|null $quiz
     */
    public function create(int $formId, string $ip, string $userAgent, string $referrer, array $values, ?array $quiz = null): int
    {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO cms_form_entries (form_id, status, ip_address, user_agent, referrer, quiz_score, quiz_max_score, quiz_passed)
                 VALUES (?, \'new\', ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $formId,
                $ip !== '' ? $ip : null,
                $userAgent !== '' ? mb_substr($userAgent, 0, 500) : null,
                $referrer !== '' ? mb_substr($referrer, 0, 500) : null,
                $quiz['score'] ?? null,
                $quiz['max_score'] ?? null,
                isset($quiz['passed']) ? ($quiz['passed'] ? 1 : 0) : null,
            ]);
            $entryId = (int) $this->pdo->lastInsertId();

            $valStmt = $this->pdo->prepare(
                'INSERT INTO cms_form_entry_values (entry_id, field_id, field_key, value_text, value_file_path) VALUES (?, ?, ?, ?, ?)'
            );
            foreach ($values as $v) {
                $valStmt->execute([
                    $entryId,
                    $v['field_id'],
                    $v['field_key'],
                    $v['value_text'],
                    $v['value_file_path'] ?? null,
                ]);
            }

            $this->pdo->commit();

            return $entryId;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function setStatus(int $entryId, int $formId, string $status): bool
    {
        if (!in_array($status, ['new', 'read', 'spam', 'trash'], true)) {
            return false;
        }
        $stmt = $this->pdo->prepare(
            'UPDATE cms_form_entries SET status = ? WHERE id = ? AND form_id = ?'
        );

        return $stmt->execute([$status, $entryId, $formId]);
    }

    public function delete(int $entryId, int $formId): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM cms_form_entries WHERE id = ? AND form_id = ?');

        return $stmt->execute([$entryId, $formId]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function exportRows(int $formId): array
    {
        $entries = $this->listForForm($formId, 'all', 5000);
        $out = [];
        foreach ($entries as $entry) {
            $values = $this->valuesForEntry((int) $entry['id']);
            $row = [
                'id' => (int) $entry['id'],
                'created_at' => (string) $entry['created_at'],
                'status' => (string) $entry['status'],
                'ip_address' => (string) ($entry['ip_address'] ?? ''),
            ];
            foreach ($values as $v) {
                $row[(string) $v['field_key']] = (string) ($v['value_text'] ?? '');
            }
            $out[] = $row;
        }

        return $out;
    }
}
