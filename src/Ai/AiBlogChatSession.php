<?php

declare(strict_types=1);

namespace App\Ai;

use App\Flash;
use App\Settings;
use PDO;

/**
 * Multi-turn chat for the admin AI assistant (session; optional DB mirror).
 *
 * @phpstan-type ChatMessage array{role: string, content: string}
 */
final class AiBlogChatSession
{
    private const SESSION_KEY = '_ai_blog_chat_v1';

    private const MAX_MESSAGES = 40;

    public static function messages(): array
    {
        Flash::start();
        $b = $_SESSION[self::SESSION_KEY] ?? null;
        if (!is_array($b)) {
            return [];
        }
        $m = $b['messages'] ?? [];

        return is_array($m) ? $m : [];
    }

    /**
     * @param list<ChatMessage> $messages
     */
    public static function setMessages(array $messages): void
    {
        Flash::start();
        if (count($messages) > self::MAX_MESSAGES) {
            $messages = array_slice($messages, -self::MAX_MESSAGES);
        }
        $_SESSION[self::SESSION_KEY] = ['messages' => $messages];
    }

    public static function clear(): void
    {
        Flash::start();
        unset($_SESSION[self::SESSION_KEY]);
    }

    public static function clearWithPersist(?PDO $pdo, ?int $userId): void
    {
        self::clear();
        if ($pdo !== null && $userId !== null && $userId > 0 && self::persistEnabled()) {
            (new AiChatMessageRepository($pdo))->deleteForUser($userId);
        }
    }

    public static function append(string $role, string $content): void
    {
        $m = self::messages();
        $m[] = ['role' => $role, 'content' => $content];
        self::setMessages($m);
    }

    public static function appendWithPersist(?PDO $pdo, ?int $userId, string $role, string $content): void
    {
        self::append($role, $content);
        if ($pdo === null || $userId === null || $userId < 1 || !self::persistEnabled()) {
            return;
        }
        if ($role !== 'user' && $role !== 'assistant') {
            return;
        }
        (new AiChatMessageRepository($pdo))->insert($userId, $role, $content);
    }

    public static function popLastIfRole(string $role): void
    {
        $m = self::messages();
        if ($m === []) {
            return;
        }
        $last = $m[array_key_last($m)];
        if (!is_array($last) || ($last['role'] ?? '') !== $role) {
            return;
        }
        array_pop($m);
        self::setMessages($m);
    }

    public static function popLastIfRoleWithPersist(?PDO $pdo, ?int $userId, string $role): void
    {
        self::popLastIfRole($role);
        if ($pdo !== null && $userId !== null && $userId > 0 && self::persistEnabled() && $role === 'user') {
            (new AiChatMessageRepository($pdo))->deleteLastForUser($userId);
        }
    }

    public static function hydrateFromDatabaseIfEnabled(PDO $pdo, ?int $userId): void
    {
        if ($userId === null || $userId < 1 || !self::persistEnabled()) {
            return;
        }
        $repo = new AiChatMessageRepository($pdo);
        $days = (int) (Settings::get('ai_chat_retention_days', '30') ?? '30');
        if ($days > 0) {
            $repo->purgeOlderThanDays($days);
        }
        $rows = $repo->listForUser($userId, self::MAX_MESSAGES);
        self::setMessages($rows);
    }

    public static function transcriptForDraft(): string
    {
        $lines = [];
        foreach (self::messages() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $role = (string) ($row['role'] ?? '');
            $content = trim((string) ($row['content'] ?? ''));
            if ($content === '') {
                continue;
            }
            $label = $role === 'user' ? 'Editor' : 'Assistant';
            $lines[] = $label . ': ' . $content;
        }

        return implode("\n\n", $lines);
    }

    private static function persistEnabled(): bool
    {
        return (Settings::get('ai_chat_persist', '0') ?? '0') === '1';
    }
}
