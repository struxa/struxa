<?php

declare(strict_types=1);

namespace StruxaAdmin;

use PDO;

final class CatalogSubmissionRepository
{
    public function __construct(
        private readonly PDO $pdo,
    ) {
    }

    public function findById(int $id): ?CatalogSubmission
    {
        $stmt = $this->pdo->prepare('SELECT * FROM cms_struxa_catalog_submissions WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? CatalogSubmission::fromRow($row) : null;
    }

    public function findBySlugAndKind(string $slug, string $kind): ?CatalogSubmission
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM cms_struxa_catalog_submissions WHERE slug = ? AND kind = ? LIMIT 1'
        );
        $stmt->execute([strtolower(trim($slug)), $kind]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? CatalogSubmission::fromRow($row) : null;
    }

    public function slugExists(string $slug, string $kind, ?int $exceptId = null): bool
    {
        $sql = 'SELECT id FROM cms_struxa_catalog_submissions WHERE slug = ? AND kind = ?';
        $params = [$slug, $kind];
        if ($exceptId !== null) {
            $sql .= ' AND id != ?';
            $params[] = $exceptId;
        }
        $sql .= ' LIMIT 1';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * @return list<CatalogSubmission>
     */
    public function listByStatus(?string $status = null, ?string $kind = null, int $limit = 100): array
    {
        $parts = [];
        $params = [];
        if ($status !== null && SubmissionStatus::isValid($status)) {
            $parts[] = 'status = ?';
            $params[] = $status;
        }
        if ($kind !== null && SubmissionKind::isValid($kind)) {
            $parts[] = 'kind = ?';
            $params[] = $kind;
        }
        $where = $parts === [] ? '' : (' WHERE ' . implode(' AND ', $parts));
        $limit = max(1, min(200, $limit));
        $stmt = $this->pdo->prepare(
            'SELECT * FROM cms_struxa_catalog_submissions' . $where . ' ORDER BY created_at DESC LIMIT ' . $limit
        );
        $stmt->execute($params);
        $out = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (is_array($row)) {
                $out[] = CatalogSubmission::fromRow($row);
            }
        }

        return $out;
    }

    /**
     * @return list<CatalogSubmission>
     */
    public function listApproved(): array
    {
        return $this->listByStatus(SubmissionStatus::APPROVED, null, 500);
    }

    /**
     * @param array<string, mixed> $manifest
     */
    public function insert(
        string $kind,
        string $gitRepoUrl,
        string $gitBranch,
        string $slug,
        string $name,
        string $version,
        string $description,
        string $author,
        array $manifest,
        ?string $screenshotPath,
        string $submitterName,
        string $submitterEmail,
        ?int $submitterUserId,
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO cms_struxa_catalog_submissions
             (kind, status, git_repo_url, git_branch, slug, name, version, description, author,
              manifest_json, screenshot_path, submitter_name, submitter_email, submitter_user_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $kind,
            SubmissionStatus::PENDING,
            $gitRepoUrl,
            $gitBranch,
            $slug,
            $name,
            $version,
            $description,
            $author,
            json_encode($manifest, JSON_THROW_ON_ERROR),
            $screenshotPath,
            $submitterName,
            $submitterEmail,
            $submitterUserId,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function setStatus(int $id, string $status, ?string $reviewerNotes, ?int $reviewedBy, ?string $publishedAt = null): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE cms_struxa_catalog_submissions
             SET status = ?, reviewer_notes = ?, reviewed_by = ?, reviewed_at = UTC_TIMESTAMP(),
                 published_at = COALESCE(?, published_at), updated_at = UTC_TIMESTAMP()
             WHERE id = ?'
        );
        $stmt->execute([$status, $reviewerNotes, $reviewedBy, $publishedAt, $id]);
    }

    public function updateScreenshot(int $id, ?string $path): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE cms_struxa_catalog_submissions SET screenshot_path = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?'
        );
        $stmt->execute([$path, $id]);
    }

    public function countPending(): int
    {
        return $this->countByStatus(SubmissionStatus::PENDING);
    }

    public function countByStatus(string $status): int
    {
        if (!SubmissionStatus::isValid($status)) {
            return 0;
        }
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM cms_struxa_catalog_submissions WHERE status = ?'
        );
        $stmt->execute([$status]);

        return (int) $stmt->fetchColumn();
    }

    public function countAll(): int
    {
        $stmt = $this->pdo->query('SELECT COUNT(*) FROM cms_struxa_catalog_submissions');

        return $stmt !== false ? (int) $stmt->fetchColumn() : 0;
    }

    /**
     * @param array<string, mixed> $manifest
     */
    public function insertApprovedImport(
        string $kind,
        string $gitRepoUrl,
        string $gitBranch,
        string $slug,
        string $name,
        string $version,
        string $description,
        string $author,
        array $manifest,
        ?int $reviewedBy,
    ): int {
        $now = gmdate('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'INSERT INTO cms_struxa_catalog_submissions
             (kind, status, git_repo_url, git_branch, slug, name, version, description, author,
              manifest_json, screenshot_path, submitter_name, submitter_email, submitter_user_id,
              reviewer_notes, reviewed_by, reviewed_at, published_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, ?, NULL, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $kind,
            SubmissionStatus::APPROVED,
            $gitRepoUrl,
            $gitBranch,
            strtolower(trim($slug)),
            $name,
            $version,
            $description,
            $author,
            json_encode($manifest, JSON_THROW_ON_ERROR),
            'Catalog import',
            'catalog@import.local',
            'Imported from repo.json',
            $reviewedBy,
            $now,
            $now,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param array<string, mixed> $manifest
     */
    public function updateApprovedImport(
        int $id,
        string $gitRepoUrl,
        string $gitBranch,
        string $name,
        string $version,
        string $description,
        string $author,
        array $manifest,
        ?int $reviewedBy,
    ): void {
        $now = gmdate('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'UPDATE cms_struxa_catalog_submissions
             SET status = ?, git_repo_url = ?, git_branch = ?, name = ?, version = ?, description = ?,
                 author = ?, manifest_json = ?, reviewer_notes = ?, reviewed_by = ?, reviewed_at = ?,
                 published_at = COALESCE(published_at, ?), updated_at = UTC_TIMESTAMP()
             WHERE id = ?'
        );
        $stmt->execute([
            SubmissionStatus::APPROVED,
            $gitRepoUrl,
            $gitBranch,
            $name,
            $version,
            $description,
            $author,
            json_encode($manifest, JSON_THROW_ON_ERROR),
            'Updated from repo.json import',
            $reviewedBy,
            $now,
            $now,
            $id,
        ]);
    }
}
