<?php

declare(strict_types=1);

namespace App\Privacy;

use PDO;
use PDOException;

/**
 * Export personal data stored in comments and form submissions for a given email address.
 */
final class PersonalDataExportService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array{ok: true, data: array<string, mixed>}|array{ok: false, error: string}
     */
    public function export(string $email): array
    {
        if (!PrivacyEmailHasher::isValidEmail($email)) {
            return ['ok' => false, 'error' => 'Enter a valid email address.'];
        }

        $normalized = PrivacyEmailHasher::normalize($email);
        $hash = PrivacyEmailHasher::hash($email);

        return [
            'ok' => true,
            'data' => [
                'email' => $normalized,
                'exported_at' => gmdate('c'),
                'comments' => $this->exportComments($hash),
                'form_submissions' => $this->exportFormSubmissions($normalized),
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function exportComments(string $emailHash): array
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT id, thread_key, parent_id, depth, status, author_name, body, client_ip, user_agent, created_at, approved_at
                 FROM cms_comments
                 WHERE author_email_hash = :hash
                 ORDER BY created_at ASC'
            );
            $stmt->execute([':hash' => $emailHash]);
            $rows = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $rows[] = [
                    'id' => (int) $row['id'],
                    'thread_key' => (string) $row['thread_key'],
                    'parent_id' => $row['parent_id'] !== null ? (int) $row['parent_id'] : null,
                    'depth' => (int) $row['depth'],
                    'status' => (string) $row['status'],
                    'author_name' => (string) $row['author_name'],
                    'body' => (string) $row['body'],
                    'client_ip' => (string) $row['client_ip'],
                    'user_agent' => $row['user_agent'] !== null ? (string) $row['user_agent'] : null,
                    'created_at' => (string) $row['created_at'],
                    'approved_at' => $row['approved_at'] !== null ? (string) $row['approved_at'] : null,
                ];
            }

            return $rows;
        } catch (PDOException) {
            return [];
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function exportFormSubmissions(string $normalizedEmail): array
    {
        try {
            $entryIds = $this->matchingFormEntryIds($normalizedEmail);
            if ($entryIds === []) {
                return [];
            }

            $placeholders = implode(',', array_fill(0, count($entryIds), '?'));
            $stmt = $this->pdo->prepare(
                'SELECT fe.id, fe.form_id, f.name AS form_name, f.slug AS form_slug,
                        fe.status, fe.ip_address, fe.user_agent, fe.referrer, fe.created_at
                 FROM cms_form_entries fe
                 INNER JOIN cms_forms f ON f.id = fe.form_id
                 WHERE fe.id IN (' . $placeholders . ')
                 ORDER BY fe.created_at ASC'
            );
            $stmt->execute($entryIds);
            $out = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $entryId = (int) $row['id'];
                $out[] = [
                    'id' => $entryId,
                    'form_id' => (int) $row['form_id'],
                    'form_name' => (string) $row['form_name'],
                    'form_slug' => (string) $row['form_slug'],
                    'status' => (string) $row['status'],
                    'ip_address' => $row['ip_address'] !== null ? (string) $row['ip_address'] : null,
                    'user_agent' => $row['user_agent'] !== null ? (string) $row['user_agent'] : null,
                    'referrer' => $row['referrer'] !== null ? (string) $row['referrer'] : null,
                    'created_at' => (string) $row['created_at'],
                    'fields' => $this->formEntryValues($entryId),
                ];
            }

            return $out;
        } catch (PDOException) {
            return [];
        }
    }

    /**
     * @return list<int>
     */
    private function matchingFormEntryIds(string $normalizedEmail): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT DISTINCT fv.entry_id
             FROM cms_form_entry_values fv
             LEFT JOIN cms_form_fields ff ON ff.id = fv.field_id
             WHERE (
                 (ff.field_type = \'email\' OR fv.field_key IN (\'email\', \'e-mail\', \'email_address\'))
                 AND LOWER(TRIM(fv.value_text)) = :email
             ) OR LOWER(TRIM(fv.value_text)) = :email2'
        );
        $stmt->execute([
            ':email' => $normalizedEmail,
            ':email2' => $normalizedEmail,
        ]);
        $ids = [];
        while ($id = $stmt->fetchColumn()) {
            $ids[] = (int) $id;
        }

        return $ids;
    }

    /**
     * @return array<string, string|null>
     */
    private function formEntryValues(int $entryId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT field_key, value_text FROM cms_form_entry_values WHERE entry_id = ? ORDER BY id ASC'
        );
        $stmt->execute([$entryId]);
        $fields = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $fields[(string) $row['field_key']] = $row['value_text'] !== null ? (string) $row['value_text'] : null;
        }

        return $fields;
    }
}
