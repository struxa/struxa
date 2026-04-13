<?php

declare(strict_types=1);

namespace App\Comment;

final class CommentValidator
{
    public const MAX_BODY = 4000;

    public const MAX_NAME = 120;

    /**
     * Plain text only: strips tags and entities suitable for storage; max length enforced.
     */
    public static function sanitizeStoredBody(string $input): string
    {
        $t = str_replace("\0", '', $input);
        $t = strip_tags($t);
        $t = html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $t = strip_tags($t);
        $t = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $t) ?? '';
        $t = trim($t);
        if (strlen($t) > self::MAX_BODY) {
            $t = substr($t, 0, self::MAX_BODY);
        }

        return $t;
    }

    /**
     * Display name from verified account fields (never from POST).
     */
    public static function displayNameFromAccount(string $username, string $email): string
    {
        $u = self::sanitizeDisplayName(trim($username));
        if ($u !== '') {
            return mb_substr($u, 0, self::MAX_NAME);
        }
        $email = trim(strtolower($email));
        if ($email !== '' && str_contains($email, '@')) {
            $at = (int) strpos($email, '@');
            $local = self::sanitizeDisplayName(substr($email, 0, $at));

            return $local !== '' ? mb_substr($local, 0, self::MAX_NAME) : 'Member';
        }

        return 'Member';
    }

    private static function sanitizeDisplayName(string $s): string
    {
        $s = preg_replace('/[^\p{L}\p{N}_.\-\s]/u', '', $s) ?? '';

        return trim($s);
    }

    /** Safe public display name from untrusted text (e.g. AI output). */
    public static function sanitizeAuthorLabel(string $raw): string
    {
        $s = self::sanitizeDisplayName(trim($raw));

        return mb_substr($s, 0, self::MAX_NAME);
    }

    /**
     * Validates a comment from a signed-in user. Ignores any posted author_name / author_email.
     *
     * @return array{ok: true, clean: array{thread_key: string, parent_id: int|null, author_name: string, author_email_hash: string, body: string, body_html: string, return_to: string, user_id: int}}|array{ok:false,error:string}
     */
    public static function validateAuthenticated(array $body, int $userId, string $userEmail, string $displayNameFromProfile): array
    {
        if ($userId < 1) {
            return ['ok' => false, 'error' => 'You must be signed in to comment.'];
        }
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

        $email = trim(strtolower($userEmail));
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 190) {
            return ['ok' => false, 'error' => 'Your account email is not valid for posting.'];
        }
        $emailHash = hash('sha256', $email);
        $name = self::displayNameFromAccount($displayNameFromProfile, $userEmail);

        $bodyRaw = self::sanitizeStoredBody((string) ($body['body'] ?? ''));
        if ($bodyRaw === '') {
            return ['ok' => false, 'error' => 'Comment cannot be empty.'];
        }

        $parentId = isset($body['parent_id']) && is_numeric($body['parent_id']) ? (int) $body['parent_id'] : null;
        if ($parentId !== null && $parentId < 1) {
            $parentId = null;
        }

        $escaped = htmlspecialchars($bodyRaw, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return ['ok' => true, 'clean' => [
            'thread_key' => $threadKey,
            'parent_id' => $parentId,
            'author_name' => $name,
            'author_email_hash' => $emailHash,
            'body' => $bodyRaw,
            'body_html' => $escaped,
            'return_to' => $returnTo,
            'user_id' => $userId,
        ]];
    }

    /**
     * @return array{ok: true, clean: array{comment_id: int, thread_key: string, return_to: string}}|array{ok:false,error:string}
     */
    public static function validateLikeRequest(array $body): array
    {
        $id = isset($body['comment_id']) && is_numeric($body['comment_id']) ? (int) $body['comment_id'] : 0;
        if ($id < 1) {
            return ['ok' => false, 'error' => 'Invalid comment.'];
        }
        $threadKey = trim((string) ($body['thread_key'] ?? ''));
        if (!preg_match('/^(page|entry):\d+$/', $threadKey)) {
            return ['ok' => false, 'error' => 'Invalid thread.'];
        }
        $returnTo = trim((string) ($body['return_to'] ?? ''));
        if ($returnTo === '' || !str_starts_with($returnTo, '/')) {
            return ['ok' => false, 'error' => 'Invalid return path.'];
        }
        if (strlen($returnTo) > 512 || preg_match('#^//|https?://#i', $returnTo) === 1) {
            return ['ok' => false, 'error' => 'Invalid return path.'];
        }

        return ['ok' => true, 'clean' => [
            'comment_id' => $id,
            'thread_key' => $threadKey,
            'return_to' => $returnTo,
        ]];
    }
}
