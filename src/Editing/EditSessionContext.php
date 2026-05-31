<?php

declare(strict_types=1);

namespace App\Editing;

final class EditSessionContext
{
    private const MAX_AUTOSAVE_BYTES = 1_048_576;

    public function __construct(
        private readonly EditLockService $locks,
        private readonly ContentAutosaveRepository $autosaves,
    ) {
    }

    /**
     * Twig variables for page/entry edit forms.
     *
     * @return array{
     *   edit_session_enabled: bool,
     *   edit_subject_type: string,
     *   edit_subject_id: int,
     *   edit_content_updated_at: string,
     *   edit_lock_status: array<string, mixed>,
     *   edit_autosave_offer: array<string, mixed>|null
     * }
     */
    public function forEditForm(
        string $subjectType,
        int $subjectId,
        string $contentUpdatedAt,
        int $userId,
    ): array {
        $lockStatus = $this->locks->statusForViewer($subjectType, $subjectId, $userId);
        $autosaveOffer = null;
        $row = $this->autosaves->findForUser($subjectType, $subjectId, $userId);
        if ($row !== null) {
            $autosaveAt = strtotime((string) ($row['updated_at'] ?? ''));
            $contentAt = strtotime($contentUpdatedAt);
            if ($autosaveAt !== false && ($contentAt === false || $autosaveAt > $contentAt)) {
                $decoded = json_decode((string) ($row['payload_json'] ?? ''), true);
                if (is_array($decoded)) {
                    $autosaveOffer = [
                        'updated_at' => (string) $row['updated_at'],
                        'payload' => $decoded,
                    ];
                }
            }
        }

        return [
            'edit_session_enabled' => true,
            'edit_subject_type' => $subjectType,
            'edit_subject_id' => $subjectId,
            'edit_content_updated_at' => $contentUpdatedAt,
            'edit_lock_status' => $lockStatus,
            'edit_autosave_offer' => $autosaveOffer,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function saveAutosave(string $subjectType, int $subjectId, int $userId, array $payload): bool
    {
        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        if (strlen($json) > self::MAX_AUTOSAVE_BYTES) {
            return false;
        }
        $this->autosaves->save($subjectType, $subjectId, $userId, $payload);

        return true;
    }

    public function clearAfterSave(string $subjectType, int $subjectId, int $userId): void
    {
        $this->autosaves->deleteForUser($subjectType, $subjectId, $userId);
    }
}
