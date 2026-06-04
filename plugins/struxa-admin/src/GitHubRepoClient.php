<?php

declare(strict_types=1);

namespace StruxaAdmin;

use App\Dist\ZipExtension;
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
        if ($info !== null) {
            if (isset($info['message']) && !isset($info['default_branch'])) {
                $apiErr = $this->apiErrorMessage($info);
                if ($apiErr !== null) {
                    $fallback = $this->resolveBranchViaRaw($owner, $repo, $branch);
                    if ($fallback['ok']) {
                        return $fallback;
                    }

                    return ['ok' => false, 'error' => $apiErr];
                }
            }
            $default = is_string($info['default_branch'] ?? null) ? (string) $info['default_branch'] : 'main';
            if ($branch === '' || $branch === 'main' || $branch === 'master') {
                return ['ok' => true, 'branch' => $default];
            }
            $ref = $this->apiGet('/repos/' . rawurlencode($owner) . '/' . rawurlencode($repo) . '/git/ref/heads/' . rawurlencode($branch));
            if ($ref === null) {
                if ($this->rawPathExists($owner, $repo, $branch, 'plugin.json')
                    || $this->rawPathExists($owner, $repo, $branch, 'theme.json')) {
                    return ['ok' => true, 'branch' => $branch];
                }

                return ['ok' => false, 'error' => 'Branch "' . $branch . '" was not found on GitHub.'];
            }

            return ['ok' => true, 'branch' => $branch];
        }

        return $this->resolveBranchViaRaw($owner, $repo, $branch);
    }

    /**
     * @return array{ok: true, branch: string}|array{ok: false, error: string}
     */
    private function resolveBranchViaRaw(string $owner, string $repo, string $branch): array
    {
        $candidates = [];
        foreach ([$branch, 'main', 'master'] as $candidate) {
            $candidate = trim($candidate);
            if ($candidate === '' || in_array($candidate, $candidates, true)) {
                continue;
            }
            $candidates[] = $candidate;
        }

        foreach ($candidates as $tryBranch) {
            if ($this->rawPathExists($owner, $repo, $tryBranch, 'plugin.json')
                || $this->rawPathExists($owner, $repo, $tryBranch, 'theme.json')
                || $this->rawPathExists($owner, $repo, $tryBranch, 'README.md')) {
                return ['ok' => true, 'branch' => $tryBranch];
            }
        }

        return ['ok' => false, 'error' => 'Could not reach GitHub for this repository (check the URL is public).'];
    }

    private function rawPathExists(string $owner, string $repo, string $branch, string $path): bool
    {
        return $this->fetchRawFile($owner, $repo, $branch, $path) !== null;
    }

    /**
     * @param array<string, mixed> $info
     */
    private function apiErrorMessage(array $info): ?string
    {
        $msg = trim((string) ($info['message'] ?? ''));
        if ($msg === '') {
            return null;
        }
        if (stripos($msg, 'rate limit') !== false) {
            return 'GitHub API rate limit reached. Add a GitHub token under Catalog settings or try again in an hour.';
        }
        if (stripos($msg, 'not found') !== false) {
            return 'Repository not found on GitHub (check the URL is public).';
        }

        return 'GitHub API error: ' . $msg;
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
        if (!ZipExtension::isAvailable()) {
            return ['ok' => false, 'error' => ZipExtension::requiredError()];
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
            '/repos/' . rawurlencode($owner) . '/' . rawurlencode($repo) . '/contents/' . implode('/', array_map('rawurlencode', explode('/', trim($path, '/'))))
            . '?ref=' . rawurlencode($branch)
        );
        if (is_array($data)) {
            // Single file or symlink object.
            if (isset($data['type'])) {
                return true;
            }
            // Directory listing: GitHub returns a list of children, not { type: "dir" }.
            if ($data !== [] && array_is_list($data)) {
                return true;
            }
        }

        return $this->pathExistsViaRawProbes($owner, $repo, $branch, $path);
    }

    /**
     * When the GitHub API is unavailable (rate limit), probe raw files that imply views/ or assets/ exist.
     */
    private function pathExistsViaRawProbes(string $owner, string $repo, string $branch, string $path): bool
    {
        $path = trim($path, '/');
        if ($path === '') {
            return false;
        }

        if ($this->fetchRawFile($owner, $repo, $branch, $path) !== null) {
            return true;
        }

        $dir = basename($path);
        $parent = dirname($path);
        $prefix = $parent === '.' || $parent === '' ? $dir : $path;

        $probes = match ($dir) {
            'views' => ['layouts/base.twig', 'layouts/theme.twig', 'content', 'page', 'pages', 'partials'],
            'assets' => ['screenshot.png', 'css/style.css', 'css/main.css', 'js/main.js', 'style.css', 'data/airports.json'],
            default => [],
        };

        foreach ($probes as $rel) {
            if ($this->fetchRawFile($owner, $repo, $branch, $prefix . '/' . $rel) !== null) {
                return true;
            }
        }

        return false;
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
        $accept = str_contains($url, 'raw.githubusercontent.com') ? '*/*' : 'application/json';
        $headerLines = [
            'User-Agent: Struxa-Catalog-Admin/1.0',
            'Accept: ' . $accept,
        ];
        if ($this->githubToken !== null && str_contains($url, 'api.github.com')) {
            $headerLines[] = 'Authorization: Bearer ' . $this->githubToken;
        }

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch !== false) {
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => $followRedirects,
                    CURLOPT_MAXREDIRS => 8,
                    CURLOPT_CONNECTTIMEOUT => 15,
                    CURLOPT_TIMEOUT => 45,
                    CURLOPT_HTTPHEADER => $headerLines,
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_SSL_VERIFYHOST => 2,
                ]);
                $raw = curl_exec($ch);
                $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                if (is_string($raw) && $code >= 200 && $code < 300 && $raw !== '' && strlen($raw) < $maxBytes) {
                    return $raw;
                }
                if ($code === 404) {
                    return null;
                }
            }
        }

        $headers = implode("\r\n", $headerLines) . "\r\n";
        $ctx = stream_context_create([
            'http' => [
                'timeout' => 45,
                'follow_location' => $followRedirects ? 1 : 0,
                'max_redirects' => 5,
                'header' => $headers,
                'ignore_errors' => true,
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
        $status = 0;
        $responseHeaders = function_exists('http_get_last_response_headers') ? http_get_last_response_headers() : null;
        if (is_array($responseHeaders) && isset($responseHeaders[0]) && preg_match('#\s(\d{3})\s#', (string) $responseHeaders[0], $sm)) {
            $status = (int) $sm[1];
        }
        if ($status === 404 || $status >= 400) {
            return null;
        }
        if ($data === '' || strlen($data) >= $maxBytes) {
            return null;
        }

        return $data;
    }
}
