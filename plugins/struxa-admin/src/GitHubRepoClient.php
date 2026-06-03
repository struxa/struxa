<?php

declare(strict_types=1);

namespace StruxaAdmin;

use ZipArchive;

/**
 * Resolve GitHub repository URLs and fetch manifests / zipballs.
 */
final class GitHubRepoClient
{
    public function __construct(
        private readonly ?string $githubToken = null,
    ) {
    }

    /**
     * @return array{ok: true, owner: string, repo: string, branch: string}|array{ok: false, error: string}
     */
    public function parseRepoUrl(string $url, string $preferredBranch = ''): array
    {
        $url = trim($url);
        if ($url === '') {
            return ['ok' => false, 'error' => 'Repository URL is required.'];
        }

        if (preg_match('#^git@github\.com:([^/]+)/([^/.]+)(?:\.git)?$#i', $url, $m)) {
            return [
                'ok' => true,
                'owner' => $m[1],
                'repo' => $m[2],
                'branch' => $preferredBranch !== '' ? $preferredBranch : 'main',
            ];
        }

        if (!preg_match('#^https?://(?:www\.)?github\.com/([^/]+)/([^/?]+)#i', $url, $m)) {
            return ['ok' => false, 'error' => 'Only public GitHub repository URLs are supported (https://github.com/owner/repo).'];
        }

        $branch = $preferredBranch;
        if (preg_match('#github\.com/[^/]+/[^/]+/tree/([^/?]+)#i', $url, $bm)) {
            $branch = $bm[1];
        }
        if ($branch === '') {
            $branch = 'main';
        }

        return [
            'ok' => true,
            'owner' => $m[1],
            'repo' => preg_replace('/\.git$/', '', $m[2]),
            'branch' => $branch,
        ];
    }

    /**
     * @return array{ok: true, branch: string}|array{ok: false, error: string}
     */
    public function resolveBranch(string $owner, string $repo, string $branch): array
    {
        $info = $this->apiGet('/repos/' . rawurlencode($owner) . '/' . rawurlencode($repo));
        if ($info === null) {
            return ['ok' => false, 'error' => 'Could not reach GitHub for this repository (check the URL is public).'];
        }
        $default = is_string($info['default_branch'] ?? null) ? (string) $info['default_branch'] : 'main';
        if ($branch === '' || $branch === 'main' || $branch === 'master') {
            return ['ok' => true, 'branch' => $default];
        }
        $ref = $this->apiGet('/repos/' . rawurlencode($owner) . '/' . rawurlencode($repo) . '/git/ref/heads/' . rawurlencode($branch));
        if ($ref === null) {
            return ['ok' => false, 'error' => 'Branch "' . $branch . '" was not found on GitHub.'];
        }

        return ['ok' => true, 'branch' => $branch];
    }

    /**
     * @return array{
     *   ok: true,
     *   package_root: string,
     *   manifest_path: string,
     *   manifest: array<string, mixed>,
     *   kind: string
     * }|array{ok: false, error: string}
     */
    public function inspectPackage(string $owner, string $repo, string $branch, string $expectedKind): array
    {
        $branchResolved = $this->resolveBranch($owner, $repo, $branch);
        if (!$branchResolved['ok']) {
            return $branchResolved;
        }
        $branch = $branchResolved['branch'];

        $candidates = ['plugin.json', 'theme.json'];
        foreach (['', $repo, str_replace('_', '-', $repo)] as $prefix) {
            foreach ($candidates as $file) {
                $path = $prefix !== '' ? $prefix . '/' . $file : $file;
                $raw = $this->fetchRawFile($owner, $repo, $branch, $path);
                if ($raw === null) {
                    continue;
                }
                try {
                    /** @var mixed $data */
                    $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
                } catch (\JsonException $e) {
                    return ['ok' => false, 'error' => 'Invalid JSON in ' . $path . ': ' . $e->getMessage()];
                }
                if (!is_array($data)) {
                    continue;
                }
                $kind = $file === 'plugin.json' ? SubmissionKind::PLUGIN : SubmissionKind::THEME;
                if ($expectedKind !== '' && $kind !== $expectedKind) {
                    return [
                        'ok' => false,
                        'error' => 'This repository contains a ' . $kind . ' manifest but you are submitting a ' . $expectedKind . '.',
                    ];
                }
                if ($kind === SubmissionKind::THEME) {
                    $themeErr = $this->validateThemeTree($owner, $repo, $branch, $prefix);
                    if ($themeErr !== null) {
                        return ['ok' => false, 'error' => $themeErr];
                    }
                }

                return [
                    'ok' => true,
                    'package_root' => $prefix,
                    'manifest_path' => $path,
                    'manifest' => $data,
                    'kind' => $kind,
                    'branch' => $branch,
                ];
            }
        }

        return [
            'ok' => false,
            'error' => 'Could not find plugin.json or theme.json at the repository root (or in a single subfolder).',
        ];
    }

    /**
     * @return array{ok: true, extract_dir: string, package_root: string}|array{ok: false, error: string}
     */
    public function downloadZipballTo(string $owner, string $repo, string $branch, string $workDir): array
    {
        $branchResolved = $this->resolveBranch($owner, $repo, $branch);
        if (!$branchResolved['ok']) {
            return $branchResolved;
        }
        $branch = $branchResolved['branch'];
        $url = 'https://api.github.com/repos/' . rawurlencode($owner) . '/' . rawurlencode($repo) . '/zipball/' . rawurlencode($branch);
        $body = $this->httpGet($url, 80_000_000, true);
        if ($body === null) {
            return ['ok' => false, 'error' => 'Could not download the repository archive from GitHub.'];
        }
        if (!is_dir($workDir) && !@mkdir($workDir, 0700, true)) {
            return ['ok' => false, 'error' => 'Could not create a temporary directory.'];
        }
        $zipPath = $workDir . '/repo.zip';
        if (file_put_contents($zipPath, $body) === false) {
            return ['ok' => false, 'error' => 'Could not save the downloaded archive.'];
        }
        if (!class_exists(ZipArchive::class)) {
            return ['ok' => false, 'error' => 'PHP zip extension is required.'];
        }
        $extractDir = $workDir . '/extract';
        if (!@mkdir($extractDir, 0700, true)) {
            return ['ok' => false, 'error' => 'Could not create extract directory.'];
        }
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return ['ok' => false, 'error' => 'Downloaded file is not a valid ZIP archive.'];
        }
        if (!$zip->extractTo($extractDir)) {
            $zip->close();

            return ['ok' => false, 'error' => 'Could not extract the repository archive.'];
        }
        $zip->close();
        @unlink($zipPath);

        $top = null;
        foreach (scandir($extractDir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $extractDir . '/' . $entry;
            if (is_dir($full)) {
                $top = $full;
                break;
            }
        }
        if ($top === null) {
            return ['ok' => false, 'error' => 'Archive did not contain a root folder.'];
        }

        return ['ok' => true, 'extract_dir' => $extractDir, 'package_root' => $top];
    }

    private function validateThemeTree(string $owner, string $repo, string $branch, string $prefix): ?string
    {
        $base = $prefix !== '' ? $prefix . '/' : '';
        foreach (['views', 'assets'] as $dir) {
            if (!$this->pathExists($owner, $repo, $branch, $base . $dir)) {
                return 'Theme repository must include "' . $base . $dir . '/" (required for Struxa themes).';
            }
        }

        return null;
    }

    private function pathExists(string $owner, string $repo, string $branch, string $path): bool
    {
        $data = $this->apiGet(
            '/repos/' . rawurlencode($owner) . '/' . rawurlencode($repo) . '/contents/' . implode('/', array_map('rawurlencode', explode('/', $path)))
            . '?ref=' . rawurlencode($branch)
        );

        return is_array($data) && isset($data['type']);
    }

    public function fetchRawFile(string $owner, string $repo, string $branch, string $path): ?string
    {
        $url = sprintf(
            'https://raw.githubusercontent.com/%s/%s/%s/%s',
            rawurlencode($owner),
            rawurlencode($repo),
            rawurlencode($branch),
            implode('/', array_map(rawurlencode(...), explode('/', $path)))
        );

        return $this->httpGet($url, 512_000, false);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function apiGet(string $path): ?array
    {
        $url = 'https://api.github.com' . $path;
        $raw = $this->httpGet($url, 512_000, false);
        if ($raw === null) {
            return null;
        }
        try {
            /** @var mixed $data */
            $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($data) ? $data : null;
    }

    private function httpGet(string $url, int $maxBytes, bool $followRedirects): ?string
    {
        $headers = "User-Agent: Struxa-Catalog-Admin/1.0\r\nAccept: application/json\r\n";
        if ($this->githubToken !== null) {
            $headers .= 'Authorization: Bearer ' . $this->githubToken . "\r\n";
        }
        $ctx = stream_context_create([
            'http' => [
                'timeout' => 45,
                'follow_location' => $followRedirects ? 1 : 0,
                'max_redirects' => 5,
                'header' => $headers,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);
        $h = @fopen($url, 'r', false, $ctx);
        if ($h === false) {
            return null;
        }
        $data = '';
        while (!feof($h) && strlen($data) < $maxBytes) {
            $chunk = fread($h, 8192);
            if ($chunk === false) {
                break;
            }
            $data .= $chunk;
        }
        fclose($h);
        if ($data === '' || strlen($data) >= $maxBytes) {
            return null;
        }

        return $data;
    }
}
