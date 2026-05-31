<?php

declare(strict_types=1);

namespace App\Editing;

final class EditLockService
{
    public const TTL_SECONDS = 120;

    public function __construct(private readonly EditLockRepository $locks)
    {
    }

    public function purgeExpired(): void
    {
        $this->locks->deleteExpired(self::TTL_SECONDS);
    }

    /**
     * @return array{
     *   status: 'acquired'|'renewed'|'blocked',
     *   lock: array<string, mixed>|null,
     *   holder: array<string, mixed>|null
     * }
     */
    public function acquireOrRenew(string $subjectType, int $subjectId, int $userId, string $lockToken): array
    {
        $this->purgeExpired();
        $existing = $this->locks->findForSubject($subjectType, $subjectId);

        if ($existing !== null && !$this->isActive($existing)) {
            $existing = null;
        }

        if ($existing === null) {
            $this->locks->upsert($subjectType, $subjectId, $userId, $lockToken);
            $lock = $this->locks->findForSubject($subjectType, $subjectId);

            return [
                'status' => 'acquired',
                'lock' => $lock,
                'holder' => null,
            ];
        }

        if ((int) $existing['user_id'] === $userId) {
            if ((string) $existing['lock_token'] === $lockToken) {
                $this->locks->touch($subjectType, $subjectId, $userId, $lockToken);
            } else {
                $this->locks->upsert($subjectType, $subjectId, $userId, $lockToken);
            }
            $lock = $this->locks->findForSubject($subjectType, $subjectId);

            return [
                'status' => 'renewed',
                'lock' => $lock,
                'holder' => null,
            ];
        }

        return [
            'status' => 'blocked',
            'lock' => $existing,
            'holder' => $this->holderFromRow($existing),
        ];
    }

    /**
     * @return array{
     *   status: 'renewed'|'blocked'|'missing',
     *   lock: array<string, mixed>|null,
     *   holder: array<string, mixed>|null
     * }
     */
    public function heartbeat(string $subjectType, int $subjectId, int $userId, string $lockToken): array
    {
        $this->purgeExpired();
        $existing = $this->locks->findForSubject($subjectType, $subjectId);

        if ($existing === null) {
            return ['status' => 'missing', 'lock' => null, 'holder' => null];
        }

        if ((int) $existing['user_id'] === $userId && (string) $existing['lock_token'] === $lockToken) {
            $this->locks->touch($subjectType, $subjectId, $userId, $lockToken);
            $lock = $this->locks->findForSubject($subjectType, $subjectId);

            return ['status' => 'renewed', 'lock' => $lock, 'holder' => null];
        }

        if ((int) $existing['user_id'] === $userId) {
            return $this->acquireOrRenew($subjectType, $subjectId, $userId, $lockToken);
        }

        return [
            'status' => 'blocked',
            'lock' => $existing,
            'holder' => $this->holderFromRow($existing),
        ];
    }

    /**
     * @return array{status: 'acquired', lock: array<string, mixed>|null, holder: null}
     */
    public function takeover(string $subjectType, int $subjectId, int $userId, string $lockToken): array
    {
        $this->locks->upsert($subjectType, $subjectId, $userId, $lockToken);
        $lock = $this->locks->findForSubject($subjectType, $subjectId);

        return ['status' => 'acquired', 'lock' => $lock, 'holder' => null];
    }

    public function release(string $subjectType, int $subjectId, int $userId, string $lockToken): void
    {
        $this->locks->release($subjectType, $subjectId, $userId, $lockToken);
    }

    /**
     * @return array{
     *   active: bool,
     *   is_mine: bool,
     *   holder: array<string, mixed>|null,
     *   lock: array<string, mixed>|null
     * }
     */
    public function statusForViewer(string $subjectType, int $subjectId, int $viewerUserId): array
    {
        $this->purgeExpired();
        $existing = $this->locks->findForSubject($subjectType, $subjectId);
        if ($existing === null || !$this->isActive($existing)) {
            return ['active' => false, 'is_mine' => false, 'holder' => null, 'lock' => null];
        }

        $isMine = (int) $existing['user_id'] === $viewerUserId;

        return [
            'active' => true,
            'is_mine' => $isMine,
            'holder' => $isMine ? null : $this->holderFromRow($existing),
            'lock' => $existing,
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    public function isActive(array $row): bool
    {
        $beat = (string) ($row['heartbeat_at'] ?? '');
        if ($beat === '') {
            return false;
        }
        $ts = strtotime($beat);

        return $ts !== false && (time() - $ts) <= self::TTL_SECONDS;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array{id: int, display_name: string, email: string}
     */
    public function holderFromRow(array $row): array
    {
        $name = trim((string) ($row['user_display_name'] ?? ''));
        if ($name === '') {
            $name = (string) ($row['user_email'] ?? 'Another editor');
        }

        return [
            'id' => (int) ($row['user_id'] ?? 0),
            'display_name' => $name,
            'email' => (string) ($row['user_email'] ?? ''),
        ];
    }
}
