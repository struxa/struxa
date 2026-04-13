<?php

declare(strict_types=1);

namespace App\Comment;

final class CommentValidator
{
    public const MAX_BODY = 4000;
    public const MAX_NAME = 120;

    /**
     * @return array{ok: true, clean: array{thread_key: string, parent_id: int|null, author_name: string, author_email_hash: string, body: string, body_html: string, return_to: string}}|array{ok:false,error:string}
     */
    public static function validate(array $body): array
    {
        $threadKey = trim((string) ($body['thread_key'] ?? ''));
        if (!preg_match('/^(page|entry):\d+$/', $threadKey)) {
            return ['ok' => false, 'error' => 'Invalid comment target.'];
        }

        $returnTo = trim((string) ($body['return_to'] ?? ''));
        if ($returnTo === '' || !str_starts_with($returnTo, '/')) {
            return ['ok' => false, 'error' => 'Invalid return path.'];
        }
        if (strlen($returnTo) > 512) {
            return ['ok' => false, 'error' => 'Return path is too long.'];
        }
        if (preg_match('#^//|https?://#i', $returnTo) === 1) {
            return ['ok' => false, 'error' => 'Invalid return path.'];
        }

        $honeypot = trim((string) ($body['website'] ?? ''));
        if ($honeypot !== '') {
            return ['ok' => false, 'error' => 'Spam detected.'];
        }

        $name = trim((string) ($body['author_name'] ?? ''));
        if ($name === '' || strlen($name) > self::MAX_NAME) {
            return ['ok' => false, 'error' => 'Enter a name (max 120 chars).'];
        }

        $email = trim((string) ($body['author_email'] ?? ''));
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 190) {
            return ['ok' => false, 'error' => 'Enter a valid email.'];
        }
        $emailHash = hash('sha256', strtolower($email));

        $bodyRaw = trim((string) ($body['body'] ?? ''));
        if ($bodyRaw === '') {
            return ['ok' => false, 'error' => 'Comment cannot be empty.'];
        }
        if (strlen($bodyRaw) > self::MAX_BODY) {
            return ['ok' => false, 'error' => 'Comment is too long.'];
        }
        $bodyHtml = nl2br(htmlspecialchars($bodyRaw, ENT_QUOTES, 'UTF-8'), false);

        $parentId = isset($body['parent_id']) && is_numeric($body['parent_id']) ? (int) $body['parent_id'] : null;
        if ($parentId !== null && $parentId < 1) {
            $parentId = null;
        }

        return ['ok' => true, 'clean' => [
            'thread_key' => $threadKey,
            'parent_id' => $parentId,
            'author_name' => $name,
            'author_email_hash' => $emailHash,
            'body' => $bodyRaw,
            'body_html' => $bodyHtml,
            'return_to' => $returnTo,
        ]];
    }
}
