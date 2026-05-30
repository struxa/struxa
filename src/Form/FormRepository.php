<?php

declare(strict_types=1);

namespace App\Form;

use PDO;

final class FormRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listForAdmin(int $limit = 200): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT f.*,
                (SELECT COUNT(*) FROM cms_form_entries e WHERE e.form_id = f.id AND e.status <> \'trash\') AS entry_count,
                (SELECT COUNT(*) FROM cms_form_entries e WHERE e.form_id = f.id AND e.status = \'new\') AS new_entry_count
             FROM cms_forms f
             ORDER BY f.updated_at DESC, f.id DESC
             LIMIT ' . max(1, min(500, $limit))
        );
        $stmt->execute();

        return array_map([$this, 'decodeRow'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM cms_forms WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $this->decodeRow($row) : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findPublishedBySlug(string $slug): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM cms_forms WHERE slug = ? AND status = \'published\' LIMIT 1'
        );
        $stmt->execute([trim($slug)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $this->decodeRow($row) : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO cms_forms
                (name, slug, description, status, form_type, submit_label, next_label, prev_label,
                 confirmation_type, confirmation_message, confirmation_redirect_url,
                 honeypot_enabled, notify_enabled, notify_emails, notify_subject, settings_json)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $data['name'],
            $data['slug'],
            $data['description'] ?? null,
            $data['status'] ?? 'draft',
            $data['form_type'] ?? 'standard',
            $data['submit_label'] ?? 'Submit',
            $data['next_label'] ?? 'Next',
            $data['prev_label'] ?? 'Previous',
            $data['confirmation_type'] ?? 'message',
            $data['confirmation_message'] ?? null,
            $data['confirmation_redirect_url'] ?? null,
            !empty($data['honeypot_enabled']) ? 1 : 0,
            !empty($data['notify_enabled']) ? 1 : 0,
            $data['notify_emails'] ?? null,
            $data['notify_subject'] ?? null,
            $this->encodeJson($data['settings'] ?? $data['settings_json'] ?? null),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE cms_forms SET
                name = ?, slug = ?, description = ?, status = ?, form_type = ?,
                submit_label = ?, next_label = ?, prev_label = ?,
                confirmation_type = ?, confirmation_message = ?, confirmation_redirect_url = ?,
                honeypot_enabled = ?, notify_enabled = ?, notify_emails = ?, notify_subject = ?,
                settings_json = ?
             WHERE id = ?'
        );

        return $stmt->execute([
            $data['name'],
            $data['slug'],
            $data['description'] ?? null,
            $data['status'] ?? 'draft',
            $data['form_type'] ?? 'standard',
            $data['submit_label'] ?? 'Submit',
            $data['next_label'] ?? 'Next',
            $data['prev_label'] ?? 'Previous',
            $data['confirmation_type'] ?? 'message',
            $data['confirmation_message'] ?? null,
            $data['confirmation_redirect_url'] ?? null,
            !empty($data['honeypot_enabled']) ? 1 : 0,
            !empty($data['notify_enabled']) ? 1 : 0,
            $data['notify_emails'] ?? null,
            $data['notify_subject'] ?? null,
            $this->encodeJson($data['settings'] ?? $data['settings_json'] ?? null),
            $id,
        ]);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM cms_forms WHERE id = ?');

        return $stmt->execute([$id]);
    }

    /**
     * @param list<array<string, mixed>> $fields
     */
    public function createFromTemplate(string $name, string $slug, string $templateKey, array $fields, array $formExtras = []): int
    {
        $formId = $this->create(array_merge([
            'name' => $name,
            'slug' => $slug,
            'status' => 'draft',
            'confirmation_message' => 'Thanks — your submission was received.',
            'notify_subject' => 'New form submission: ' . $name,
            'honeypot_enabled' => 1,
            'notify_enabled' => 1,
        ], $formExtras));

        if ($fields !== []) {
            $fieldRepo = new FormFieldRepository($this->pdo);
            foreach ($fields as $field) {
                $field['form_id'] = $formId;
                $fieldRepo->create($field);
            }
        }

        return $formId;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function decodeRow(array $row): array
    {
        $row['settings'] = $this->decodeJson($row['settings_json'] ?? null);

        return $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeJson(?string $json): ?array
    {
        if ($json === null || trim($json) === '') {
            return null;
        }
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function encodeJson(mixed $data): ?string
    {
        if ($data === null || $data === '') {
            return null;
        }
        if (is_string($data)) {
            return trim($data) === '' ? null : $data;
        }
        if (!is_array($data)) {
            return null;
        }

        return json_encode($data, JSON_THROW_ON_ERROR);
    }
}
