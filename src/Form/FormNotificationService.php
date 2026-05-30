<?php

declare(strict_types=1);

namespace App\Form;

final class FormNotificationService
{
    /**
     * @param array<string, mixed> $form
     * @param list<array{field_key: string, value_text: string|null}> $values
     * @param list<array<string, mixed>> $fields
     */
    public function sendAdminNotification(array $form, array $values, array $fields): void
    {
        if (empty($form['notify_enabled'])) {
            return;
        }

        $raw = trim((string) ($form['notify_emails'] ?? ''));
        if ($raw === '') {
            return;
        }

        $recipients = [];
        foreach (preg_split('/[,;\s]+/', $raw) ?: [] as $email) {
            $email = trim((string) $email);
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $recipients[] = $email;
            }
        }
        $recipients = array_values(array_unique($recipients));
        if ($recipients === []) {
            return;
        }

        $labels = [];
        foreach ($fields as $field) {
            if (($field['field_type'] ?? '') === FormFieldType::HONEYPOT) {
                continue;
            }
            $labels[(string) $field['field_key']] = (string) ($field['label'] ?? $field['field_key']);
        }

        $lines = [];
        foreach ($values as $v) {
            $key = (string) $v['field_key'];
            $label = $labels[$key] ?? $key;
            $lines[] = $label . ': ' . (string) ($v['value_text'] ?? '');
        }

        $subject = trim((string) ($form['notify_subject'] ?? ''));
        if ($subject === '') {
            $subject = 'New submission: ' . (string) ($form['name'] ?? 'Form');
        }

        $body = "New submission for \"" . (string) ($form['name'] ?? 'Form') . "\"\n\n"
            . implode("\n", $lines)
            . "\n\n— Sent by Struxa CMS";

        $from = trim((string) ($_ENV['CMS_MAIL_FROM'] ?? ''));
        if ($from === '' || !filter_var($from, FILTER_VALIDATE_EMAIL)) {
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $from = 'noreply@' . preg_replace('/[^a-z0-9.-]+/i', '', $host);
        }

        $headers = [
            'MIME-Version: 1.0',
            'Content-type: text/plain; charset=UTF-8',
            'From: ' . $from,
        ];

        foreach ($recipients as $to) {
            @mail($to, $subject, $body, implode("\r\n", $headers));
        }
    }
}
