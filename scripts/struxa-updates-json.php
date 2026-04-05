<?php

declare(strict_types=1);

/**
 * Standalone Struxa updates.json generator for your company site (e.g. struxapoint.com/dist/struxa-updates-json.php).
 *
 * Deploy this only on the vendor website — not required for people who install or clone the CMS from Git.
 * Self-hosted sites use STRUXA_UPDATES_JSON_URL (your feed) or STRUXA_UPDATES_GITHUB_REPO instead.
 *
 * Reads version + download URL from GitHub (latest Release, or composer.json on a branch if there is no release).
 * When using the branch fallback, resolves the branch tip via the GitHub API, then fetches composer.json at that
 * commit SHA on raw.githubusercontent.com — avoids stale CDN responses for .../main/composer.json.
 * No Composer — upload this single file + optional .env-style config via web server SetEnv.
 *
 * Usage:
 *   - Web: https://example.com/dist/struxa-updates-json.php  → application/json (path is up to you)
 *   - Web refresh cache: ?refresh=1  (optional secret: STRUXA_UPDATES_GEN_SECRET in Apache/Nginx env)
 *   - CLI: php struxa-updates-json.php
 *   - CLI write file: php struxa-updates-json.php --write=/path/to/updates.json
 *   - Health (no GitHub call): ?ping=1 → plain text (verify PHP runs in this directory)
 *
 * Web: file must start with <?php only (no shebang) so nothing is sent before JSON headers.
 *
 * Optional environment variables (recommended on the server instead of editing constants):
 *   STRUXA_UPDATES_GITHUB_REPO   default struxa/struxa
 *   STRUXA_UPDATES_GITHUB_REF    branch for composer.json fallback (default main)
 *   STRUXA_UPDATES_RELEASE_URL   marketing / changelog page (default https://struxapoint.com/releases)
 *   GITHUB_TOKEN                 PAT for higher API rate limits (no repo scope needed for public repos)
 *   STRUXA_UPDATES_GEN_SECRET    if set, ?refresh=1 must pass &key=<secret>
 *   STRUXA_UPDATES_CACHE_TTL     seconds (default 3600)
 *   STRUXA_UPDATES_CACHE_PATH    writable JSON cache file path (default: same dir as this script + .cache.json)
 */

// ---- Editable defaults (override with env vars above) ----
const DEFAULT_REPO = 'struxa/struxa';
const DEFAULT_BRANCH = 'main';
const DEFAULT_RELEASE_PAGE = 'https://struxapoint.com/releases';
const DEFAULT_SEVERITY = 'minor';
const DEFAULT_CACHE_TTL = 3600;
// -----------------------------------------------------------

const MAX_COMPOSER_BYTES = 65536;

/**
 * @return array{0: string, 1: int} [payload, httpCode] or ['', 0] on failure
 */
function struxa_http_get(string $url, array $headers): array
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch === false) {
            return ['', 0];
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($raw === false || !is_string($raw)) {
            return ['', 0];
        }

        return [$raw, $code];
    }

    $headerLine = implode("\r\n", $headers);
    $ctx = stream_context_create([
        'http' => [
            'timeout' => 20,
            'follow_location' => 1,
            'max_redirects' => 5,
            'header' => $headerLine . "\r\n",
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) {
        return ['', 0];
    }
    $code = 200;
    if (function_exists('http_get_last_response_headers')) {
        $rh = http_get_last_response_headers();
        if (is_array($rh)) {
            foreach ($rh as $line) {
                if (preg_match('#^HTTP/\S+\s+(\d+)#', $line, $m)) {
                    $code = (int) $m[1];
                    break;
                }
            }
        }
    }

    return [$raw, $code];
}

function struxa_env(string $key, string $default = ''): string
{
    $v = getenv($key);
    if ($v !== false && $v !== '') {
        return trim($v);
    }

    return $default;
}

function struxa_normalize_tag(string $tag): string
{
    $tag = trim($tag);
    if ($tag === '') {
        return '';
    }
    if (preg_match('/^v\d/', $tag) === 1) {
        return substr($tag, 1);
    }

    return $tag;
}

function struxa_valid_semver(string $v): bool
{
    return preg_match('/^\d+\.\d+\.\d+([.-][0-9A-Za-z.-]+)?$/', $v) === 1;
}

/**
 * Resolve branch (or tag) tip to a full commit SHA via GitHub API. Empty string on failure.
 */
function struxa_github_ref_head_sha(string $repo, string $ref, array $baseHeaders): string
{
    $url = 'https://api.github.com/repos/' . $repo . '/commits/' . rawurlencode($ref);
    [$raw, $code] = struxa_http_get($url, $baseHeaders);
    if ($raw === '' || $code !== 200) {
        return '';
    }
    try {
        /** @var mixed $data */
        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return '';
    }
    if (!is_array($data) || !isset($data['sha']) || !is_string($data['sha'])) {
        return '';
    }
    $sha = strtolower(trim($data['sha']));
    if (preg_match('/^[a-f0-9]{40}$/', $sha) !== 1) {
        return '';
    }

    return $sha;
}

function struxa_truncate(string $text, int $max): string
{
    $collapsed = preg_replace('/\s+/u', ' ', $text);
    $text = trim(is_string($collapsed) ? $collapsed : $text);
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        if (mb_strlen($text) <= $max) {
            return $text;
        }

        return mb_substr($text, 0, $max) . '…';
    }
    if (strlen($text) <= $max) {
        return $text;
    }

    return substr($text, 0, $max) . '…';
}

/**
 * @return array{schema_version: int, cms: array<string, string>}|array{error: string}
 */
function struxa_build_updates_payload(string $repo, string $branch, string $releasePage, string $severity): array
{
    $token = struxa_env('GITHUB_TOKEN', '');
    $baseHeaders = [
        'User-Agent: Struxa-Updates-Generator/1 (+https://struxapoint.com)',
        'Accept: application/vnd.github+json',
    ];
    if ($token !== '') {
        $baseHeaders[] = 'Authorization: Bearer ' . $token;
    }

    $apiLatest = 'https://api.github.com/repos/' . $repo . '/releases/latest';
    [$raw, $code] = struxa_http_get($apiLatest, $baseHeaders);

    if ($raw !== '' && $code === 200) {
        try {
            /** @var mixed $data */
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $data = null;
        }
        if (is_array($data) && isset($data['tag_name']) && is_string($data['tag_name'])) {
            $ver = struxa_normalize_tag($data['tag_name']);
            if ($ver !== '' && struxa_valid_semver($ver)) {
                $name = isset($data['name']) && is_string($data['name']) ? trim($data['name']) : '';
                $title = $name !== '' ? $name : ('Struxa ' . $ver);
                $body = isset($data['body']) && is_string($data['body']) ? trim($data['body']) : '';
                $summary = $body !== '' ? struxa_truncate($body, 400) : ('Struxa CMS ' . $ver . ' — see release notes.');
                $ghUrl = isset($data['html_url']) && is_string($data['html_url']) ? trim($data['html_url']) : '';
                $releaseUrl = ($ghUrl !== '' && str_starts_with($ghUrl, 'https://')) ? $ghUrl : $releasePage;
                $zip = '';
                if (isset($data['zipball_url']) && is_string($data['zipball_url'])) {
                    $z = trim($data['zipball_url']);
                    if ($z !== '' && str_starts_with($z, 'https://')) {
                        $zip = $z;
                    }
                }
                if ($zip === '') {
                    $tagRaw = trim((string) $data['tag_name']);
                    if ($tagRaw !== '') {
                        $zip = 'https://github.com/' . $repo . '/archive/refs/tags/' . rawurlencode($tagRaw) . '.zip';
                    }
                }

                $cms = [
                    'latest_version' => $ver,
                    'title' => $title,
                    'summary' => $summary,
                    'release_url' => $releaseUrl,
                    'severity' => $severity,
                    'download_url' => $zip,
                ];

                return ['schema_version' => 1, 'cms' => $cms];
            }
        }
    }

    $headSha = struxa_github_ref_head_sha($repo, $branch, $baseHeaders);
    $composerRef = $headSha !== '' ? $headSha : rawurlencode($branch);
    $composerUrl = 'https://raw.githubusercontent.com/' . $repo . '/' . $composerRef . '/composer.json';
    $composerHeaders = [
        'User-Agent: Struxa-Updates-Generator/1 (+https://struxapoint.com)',
        'Accept: application/json',
    ];
    [$cRaw, $cCode] = struxa_http_get($composerUrl, $composerHeaders);
    if ($cRaw === '' || $cCode !== 200 || strlen($cRaw) > MAX_COMPOSER_BYTES) {
        return ['error' => 'Could not load GitHub release or composer.json for ' . $repo . ' (branch ' . $branch . ').'];
    }
    try {
        /** @var mixed $composer */
        $composer = json_decode($cRaw, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return ['error' => 'composer.json is not valid JSON.'];
    }
    $ver = is_array($composer) && isset($composer['version']) && is_string($composer['version'])
        ? trim($composer['version'])
        : '';
    if ($ver === '' || !struxa_valid_semver($ver)) {
        return ['error' => 'composer.json has no valid semver version field.'];
    }
    $zip = $headSha !== ''
        ? ('https://github.com/' . $repo . '/archive/' . $headSha . '.zip')
        : ('https://github.com/' . $repo . '/archive/refs/heads/' . rawurlencode($branch) . '.zip');
    $cms = [
        'latest_version' => $ver,
        'title' => 'Struxa ' . $ver,
        'summary' => 'Latest source from GitHub branch ' . $branch . ' (no published Release yet). See repository for changes.',
        'release_url' => $releasePage,
        'severity' => $severity,
        'download_url' => $zip,
    ];

    return ['schema_version' => 1, 'cms' => $cms];
}

function struxa_repo_config(): array
{
    $repo = struxa_env('STRUXA_UPDATES_GITHUB_REPO', DEFAULT_REPO);
    if (!preg_match('#^[a-zA-Z0-9_.-]+/[a-zA-Z0-9_.-]+$#', $repo)) {
        $repo = DEFAULT_REPO;
    }
    $branch = struxa_env('STRUXA_UPDATES_GITHUB_REF', DEFAULT_BRANCH);
    if ($branch === '' || strlen($branch) > 200 || !preg_match('#^[a-zA-Z0-9._/-]+$#', $branch)) {
        $branch = DEFAULT_BRANCH;
    }
    $releasePage = struxa_env('STRUXA_UPDATES_RELEASE_URL', DEFAULT_RELEASE_PAGE);
    if ($releasePage !== '' && !str_starts_with($releasePage, 'https://')) {
        $releasePage = DEFAULT_RELEASE_PAGE;
    }
    $severity = strtolower(struxa_env('STRUXA_UPDATES_SEVERITY', DEFAULT_SEVERITY));
    if (!in_array($severity, ['minor', 'major', 'patch', 'info'], true)) {
        $severity = DEFAULT_SEVERITY;
    }

    return [$repo, $branch, $releasePage, $severity];
}

// ---- Run ----

$isCli = PHP_SAPI === 'cli';

if (!$isCli && isset($_GET['ping'])) {
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo 'struxa-updates-json ok ' . PHP_VERSION;
    exit;
}

$writePath = null;
if ($isCli) {
    foreach (array_slice($argv ?? [], 1) as $arg) {
        if (str_starts_with($arg, '--write=')) {
            $writePath = substr($arg, 8);
            break;
        }
    }
}

[$repo, $branch, $releasePage, $severity] = struxa_repo_config();

$cacheTtl = (int) struxa_env('STRUXA_UPDATES_CACHE_TTL', (string) DEFAULT_CACHE_TTL);
if ($cacheTtl < 60) {
    $cacheTtl = 60;
}
if ($cacheTtl > 86400) {
    $cacheTtl = 86400;
}

$scriptDir = dirname(__FILE__);
$defaultCachePath = $scriptDir . DIRECTORY_SEPARATOR . '.struxa-updates-cache.json';
$cachePath = struxa_env('STRUXA_UPDATES_CACHE_PATH', $defaultCachePath);

$bypassCache = false;
if (!$isCli) {
    $secret = struxa_env('STRUXA_UPDATES_GEN_SECRET', '');
    $refresh = isset($_GET['refresh']) && ($_GET['refresh'] === '1' || $_GET['refresh'] === 'true');
    if ($refresh) {
        if ($secret !== '') {
            $key = isset($_GET['key']) ? (string) $_GET['key'] : '';
            $bypassCache = hash_equals($secret, $key);
        } else {
            $bypassCache = true;
        }
    }
} elseif (in_array('--refresh', array_slice($argv ?? [], 1), true)) {
    $bypassCache = true;
}

$payload = null;
if (!$bypassCache && is_readable($cachePath)) {
    $cachedRaw = @file_get_contents($cachePath);
    if ($cachedRaw !== false && $cachedRaw !== '') {
        try {
            /** @var mixed $cached */
            $cached = json_decode($cachedRaw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $cached = null;
        }
        if (is_array($cached) && isset($cached['expires_at'], $cached['payload']) && is_array($cached['payload'])) {
            if (time() < (int) $cached['expires_at']) {
                $payload = $cached['payload'];
            }
        }
    }
}

if ($payload === null) {
    $built = struxa_build_updates_payload($repo, $branch, $releasePage, $severity);
    if (isset($built['error'])) {
        if ($isCli) {
            fwrite(STDERR, $built['error'] . "\n");
            exit(1);
        }
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8', true, 503);
            header('Cache-Control: no-store');
        }
        try {
            echo json_encode(['schema_version' => 1, 'error' => $built['error']], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            echo '{"schema_version":1,"error":"generator_failed"}';
        }
        exit;
    }
    $payload = $built;
    $dir = dirname($cachePath);
    if (is_dir($dir) && is_writable($dir)) {
        $wrap = [
            'expires_at' => time() + $cacheTtl,
            'payload' => $payload,
        ];
        @file_put_contents($cachePath, json_encode($wrap, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}

$jsonFlags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;
if ($isCli) {
    $jsonFlags |= JSON_PRETTY_PRINT;
}
try {
    $json = json_encode($payload, $jsonFlags);
} catch (JsonException) {
    if ($isCli) {
        fwrite(STDERR, "JSON encode failed.\n");
        exit(1);
    }
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8', true, 500);
        header('Cache-Control: no-store');
    }
    echo '{"schema_version":1,"error":"json_encode_failed"}';
    exit;
}

if ($isCli) {
    if ($writePath !== null) {
        if (@file_put_contents($writePath, $json . "\n") === false) {
            fwrite(STDERR, "Could not write: {$writePath}\n");
            exit(1);
        }
        echo "Wrote {$writePath}\n";
        exit(0);
    }
    echo $json . "\n";
    exit(0);
}

if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: public, max-age=' . min($cacheTtl, 3600));
}

echo $json;
