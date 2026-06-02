<?php

declare(strict_types=1);

namespace StruxaAdmin;

final class CatalogSubmission
{
    /**
     * @param array<string, mixed> $manifest
     */
    public function __construct(
        public readonly int $id,
        public readonly string $kind,
        public readonly string $status,
        public readonly string $gitRepoUrl,
        public readonly string $gitBranch,
        public readonly string $slug,
        public readonly string $name,
        public readonly string $version,
        public readonly string $description,
        public readonly string $author,
        public readonly array $manifest,
        public readonly ?string $screenshotPath,
        public readonly string $submitterName,
        public readonly string $submitterEmail,
        public readonly ?int $submitterUserId,
        public readonly ?string $reviewerNotes,
        public readonly ?int $reviewedBy,
        public readonly ?string $reviewedAt,
        public readonly ?string $publishedAt,
        public readonly string $createdAt,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $manifest = [];
        $raw = $row['manifest_json'] ?? null;
        if (is_string($raw) && $raw !== '') {
            try {
                $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    $manifest = $decoded;
                }
            } catch (\JsonException) {
                $manifest = [];
            }
        }

        return new self(
            (int) ($row['id'] ?? 0),
            (string) ($row['kind'] ?? ''),
            (string) ($row['status'] ?? SubmissionStatus::PENDING),
            (string) ($row['git_repo_url'] ?? ''),
            (string) ($row['git_branch'] ?? 'main'),
            (string) ($row['slug'] ?? ''),
            (string) ($row['name'] ?? ''),
            (string) ($row['version'] ?? '1.0.0'),
            (string) ($row['description'] ?? ''),
            (string) ($row['author'] ?? ''),
            $manifest,
            isset($row['screenshot_path']) && $row['screenshot_path'] !== null && $row['screenshot_path'] !== ''
                ? (string) $row['screenshot_path']
                : null,
            (string) ($row['submitter_name'] ?? ''),
            (string) ($row['submitter_email'] ?? ''),
            isset($row['submitter_user_id']) && $row['submitter_user_id'] !== null ? (int) $row['submitter_user_id'] : null,
            isset($row['reviewer_notes']) && $row['reviewer_notes'] !== null ? (string) $row['reviewer_notes'] : null,
            isset($row['reviewed_by']) && $row['reviewed_by'] !== null ? (int) $row['reviewed_by'] : null,
            isset($row['reviewed_at']) && $row['reviewed_at'] !== null ? (string) $row['reviewed_at'] : null,
            isset($row['published_at']) && $row['published_at'] !== null ? (string) $row['published_at'] : null,
            (string) ($row['created_at'] ?? ''),
        );
    }
}
