<?php

declare(strict_types=1);

namespace StruxaAdmin;

use PDO;

final class CatalogSubmissionEditor
{
    public function __construct(
        private readonly CatalogSubmissionRepository $submissions,
        private readonly GitHubRepoClient $github,
        private readonly CatalogPublisher $publisher,
        private readonly CatalogSubmissionScreenshotApplier $screenshotApplier,
    ) {
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array{ok: true, regenerated: bool}|array{ok: false, errors: list<string>}
     */
    public function update(PDO $pdo, int $id, array $body): array
    {
        $row = $this->submissions->findById($id);
        if ($row === null) {
            return ['ok' => false, 'errors' => ['Submission not found.']];
        }

        $errors = [];
        $name = trim((string) ($body['name'] ?? ''));
        $version = trim((string) ($body['version'] ?? ''));
        $description = trim((string) ($body['description'] ?? ''));
        $author = trim((string) ($body['author'] ?? ''));
        $gitRepoUrl = trim((string) ($body['git_repo_url'] ?? ''));
        $gitBranch = trim((string) ($body['git_branch'] ?? ''));
        $requiresCms = trim((string) ($body['requires_cms_version'] ?? ''));
        $submitterUsername = trim((string) ($body['submitter_username'] ?? ''));
        $submitterEmailInput = trim((string) ($body['submitter_email'] ?? ''));
        $clearSubmitter = !empty($body['clear_submitter_link']);
        $clearScreenshot = !empty($body['clear_screenshot']);
        $screenshotMediaId = isset($body['screenshot_media_id']) && ctype_digit((string) $body['screenshot_media_id'])
            ? (int) $body['screenshot_media_id']
            : 0;

        if ($name === '') {
            $errors[] = 'Name is required.';
        } elseif (mb_strlen($name) > 255) {
            $errors[] = 'Name is too long (max 255 characters).';
        }
        if ($version === '') {
            $errors[] = 'Version is required.';
        } elseif (mb_strlen($version) > 64) {
            $errors[] = 'Version is too long (max 64 characters).';
        }
        if (mb_strlen($description) > 8000) {
            $errors[] = 'Description is too long (max 8000 characters).';
        }
        if (mb_strlen($author) > 255) {
            $errors[] = 'Author is too long (max 255 characters).';
        }
        if ($gitRepoUrl === '') {
            $errors[] = 'Git repository URL is required.';
        }
        if ($gitBranch === '') {
            $gitBranch = 'main';
        } elseif (mb_strlen($gitBranch) > 120) {
            $errors[] = 'Branch name is too long (max 120 characters).';
        }
        if ($requiresCms !== '' && !preg_match('/^\d+\.\d+\.\d+$/', $requiresCms)) {
            $errors[] = 'Requires CMS version must be semver like 1.1.33 (or leave blank).';
        }
        if ($submitterEmailInput !== '' && filter_var($submitterEmailInput, FILTER_VALIDATE_EMAIL) === false) {
            $errors[] = 'Submitter email is not valid.';
        } elseif (mb_strlen($submitterEmailInput) > 191) {
            $errors[] = 'Submitter email is too long (max 191 characters).';
        }

        $parsed = $this->github->parseRepoUrl($gitRepoUrl, $gitBranch);
        if (!$parsed['ok']) {
            $errors[] = $parsed['error'];
        }

        $submitterUserId = $row->submitterUserId;
        $submitterEmail = $row->submitterEmail;
        $submitterUsernameStored = $row->submitterUsername;

        if ($clearSubmitter) {
            $submitterUserId = null;
            $submitterUsernameStored = '';
        } elseif ($submitterUsername !== '') {
            $member = CatalogMemberLookup::resolveUsername($pdo, $submitterUsername);
            if (!$member['ok']) {
                $errors[] = $member['error'];
            } else {
                $submitterUserId = $member['cms_user_id'];
                $submitterUsernameStored = $member['username'];
                if ($submitterEmailInput === '' && $member['email'] !== '') {
                    $submitterEmail = $member['email'];
                }
            }
        }

        if ($submitterEmailInput !== '') {
            $submitterEmail = $submitterEmailInput;
        }

        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors];
        }

        /** @var array{ok: true, owner: string, repo: string, branch: string} $parsed */
        $manifest = $row->manifest;
        $manifest['name'] = $name;
        $manifest['version'] = $version;
        $manifest['description'] = $description;
        $manifest['author'] = $author;
        if ($requiresCms !== '') {
            $manifest['requires_cms_version'] = $requiresCms;
        } else {
            unset($manifest['requires_cms_version']);
        }

        $this->submissions->updateDetails(
            $id,
            'https://github.com/' . $parsed['owner'] . '/' . $parsed['repo'],
            $gitBranch,
            $name,
            $version,
            $description,
            $author,
            $manifest,
            $submitterUserId,
            $submitterUsernameStored,
            $submitterEmail,
        );

        $screenshotResult = $this->applyScreenshotChanges($row, $clearScreenshot, $screenshotMediaId);
        if (!$screenshotResult['ok']) {
            return ['ok' => false, 'errors' => [$screenshotResult['error']]];
        }

        $regenerated = false;
        if ($row->status === SubmissionStatus::APPROVED) {
            $regen = $this->publisher->regenerateCatalog();
            if (!$regen['ok']) {
                return ['ok' => false, 'errors' => ['Saved, but catalog regenerate failed: ' . $regen['error']]];
            }
            $regenerated = true;
        }

        return ['ok' => true, 'regenerated' => $regenerated];
    }

    /**
     * @param CatalogSubmission $row
     *
     * @return array{ok: true}|array{ok: false, error: string}
     */
    private function applyScreenshotChanges(CatalogSubmission $row, bool $clearScreenshot, int $screenshotMediaId): array
    {
        $oldPath = $row->screenshotPath;

        if ($clearScreenshot) {
            $this->screenshotApplier->deleteStoredFile($oldPath);
            $this->submissions->updateScreenshot($row->id, null);

            return ['ok' => true];
        }

        if ($screenshotMediaId < 1) {
            return ['ok' => true];
        }

        $stored = $this->screenshotApplier->applyFromMediaId($screenshotMediaId, $row->slug, $row->kind);
        if (!$stored['ok']) {
            return ['ok' => false, 'error' => $stored['error']];
        }

        $newPath = $stored['relative_path'];
        if ($newPath === '') {
            return ['ok' => true];
        }

        if ($oldPath !== null && $oldPath !== '' && $oldPath !== $newPath) {
            $this->screenshotApplier->deleteStoredFile($oldPath);
        }

        $this->submissions->updateScreenshot($row->id, $newPath);

        return ['ok' => true];
    }
}
