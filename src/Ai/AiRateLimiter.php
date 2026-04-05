<?php

declare(strict_types=1);

namespace App\Ai;

use App\Settings;
use PDO;

final class AiRateLimiter
{
    private AiUsageRepository $usage;

    public function __construct(PDO $pdo, ?AiUsageRepository $usage = null)
    {
        $this->usage = $usage ?? new AiUsageRepository($pdo);
    }

    public static function create(PDO $pdo): self
    {
        return new self($pdo, new AiUsageRepository($pdo));
    }

    public function maxChatsPerHour(): int
    {
        $v = (int) (Settings::get('ai_rate_chat_per_hour', '60') ?? '60');

        return max(0, min(500, $v));
    }

    public function maxDraftsPerDay(): int
    {
        $v = (int) (Settings::get('ai_rate_draft_per_day', '40') ?? '40');

        return max(0, min(200, $v));
    }

    public function assertChatAllowed(int $userId): void
    {
        $max = $this->maxChatsPerHour();
        if ($max === 0) {
            throw new AiRateLimitExceededException('AI chat is disabled (rate limit set to 0).');
        }
        $n = $this->usage->countChatsSince($userId, 1, 'chat');
        if ($n >= $max) {
            throw new AiRateLimitExceededException(
                'You have reached the hourly limit of ' . $max . ' AI chat messages. Try again later.'
            );
        }
    }

    public function assertDraftAllowed(int $userId): void
    {
        $max = $this->maxDraftsPerDay();
        if ($max === 0) {
            throw new AiRateLimitExceededException('AI draft creation is disabled (rate limit set to 0).');
        }
        $n = $this->usage->countDraftsSince($userId, 24);
        if ($n >= $max) {
            throw new AiRateLimitExceededException(
                'You have reached the daily limit of ' . $max . ' AI draft creations. Try again tomorrow.'
            );
        }
    }

    public function recordChat(int $userId): void
    {
        $this->usage->record($userId, 'chat', null);
    }

    public function recordDraft(int $userId, int $contentTypeId): void
    {
        $meta = json_encode(['content_type_id' => $contentTypeId], JSON_THROW_ON_ERROR);
        $this->usage->record($userId, 'draft', $meta);
    }

    /**
     * @return array{chat_24h: int, draft_24h: int, chat_7d: int, draft_7d: int}
     */
    public function totalsForUser(int $userId): array
    {
        return $this->usage->totalsForUser($userId);
    }

    /**
     * @return list<array{user_id: int, email: string, chat_7d: int, draft_7d: int}>
     */
    public function topUsers7d(int $limit = 15): array
    {
        return $this->usage->topUsersByVolume7d($limit);
    }
}
