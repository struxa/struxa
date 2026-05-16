<?php

declare(strict_types=1);

namespace App\Update;

use App\CmsVersion;
use App\Filesystem\SafeDirectoryRemoval;
use App\Theme\ThemeRemoteInstaller;
use ZipArchive;

/**
 * Applies a remote Struxa ZIP (GitHub archive or vendor-built package) over the install.
 * Preserves .env, storage/, public/uploads/, plugins/ (install via catalog), and each plugin's vendor/ tree.
 */
final class CmsSelfUpdater
{
    private const MAX_ZIP_BYTES = 120_000_000;

    /**
     * @param array<string, mixed> $status CmsUpdateChecker::check() result
     * @return array{
     *   ok: bool,
     *   message: string,
     *   warnings: list<string>,
     *   applied_version?: string,
     *   steps?: list<array{id: string, label: string, status: 'ok'|'warn'|'error', detail?: string}>
     * }
     */
    public function apply(string $projectRoot, array $status): array
    {
        $warnings = [];
        $projectRoot = rtrim($projectRoot, DIRECTORY_SEPARATOR);
        $pr = realpath($projectRoot);
        if ($pr === false || !is_dir($pr)) {
            return ['ok' => false, 'message' => 'Invalid project root.', 'warnings' => []];
        }
        $projectRoot = $pr;

        if (empty($status['ok']) || empty($status['update_available']) || empty($status['download_url'])) {
            return ['ok' => false, 'message' => 'No downloadable update is available right now.', 'warnings' => []];
        }
        $url = trim((string) $status['download_url']);
        if ($url === '' || !str_starts_with($url, 'https://')) {
            return ['ok' => false, 'message' => 'Invalid download URL.', 'warnings' => []];
        }
        if (!ThemeRemoteInstaller::isDownloadUrlHostAllowed($url)) {
            return ['ok' => false, 'message' => 'Download host is not allowed for automatic updates.', 'warnings' => []];
        }
        $latest = isset($status['latest_version']) && is_string($status['latest_version'])
            ? trim($status['latest_version'])
            : '';
        if ($latest === '' || version_compare($latest, CmsVersion::CURRENT, '<=')) {
            return ['ok' => false, 'message' => 'Installed version is already up to date.', 'warnings' => []];
        }

        if (!class_exists(ZipArchive::class)) {
            return ['ok' => false, 'message' => 'PHP zip extension (ZipArchive) is required.', 'warnings' => []];
        }

        @set_time_limit(600);

        $fetched = $this->fetchUpdateZip($url, self::MAX_ZIP_BYTES);
        if ($fetched['body'] === null) {
            $detail = trim($fetched['detail']);
            $msg = 'Could not download the update package (network, timeout, HTTP error, or ZIP larger than '
                . (int) round(self::MAX_ZIP_BYTES / 1_000_000) . 'MB).';
            if ($detail !== '') {
                $msg .= ' ' . $detail;
            }

            return ['ok' => false, 'message' => $msg, 'warnings' => []];
        }
        $body = $fetched['body'];

        $work = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'struxa-self-update-' . bin2hex(random_bytes(8));
        if (!@mkdir($work, 0700, true) || !is_dir($work)) {
            return ['ok' => false, 'message' => 'Could not create a temporary working directory.', 'warnings' => []];
        }

        try {
            $zipPath = $work . DIRECTORY_SEPARATOR . 'update.zip';
            $extractDir = $work . DIRECTORY_SEPARATOR . 'extract';
            if (file_put_contents($zipPath, $body) === false) {
                return ['ok' => false, 'message' => 'Could not save the downloaded package.', 'warnings' => []];
            }
            if (!@mkdir($extractDir, 0700, true)) {
                return ['ok' => false, 'message' => 'Could not create extract directory.', 'warnings' => []];
            }
            $zip = new ZipArchive();
            if ($zip->open($zipPath) !== true) {
                return ['ok' => false, 'message' => 'Downloaded file is not a valid ZIP archive.', 'warnings' => []];
            }
            if (!$this->extractZipSafely($zip, $extractDir)) {
                $zip->close();

                return ['ok' => false, 'message' => 'Update archive failed security checks.', 'warnings' => []];
            }
            $zip->close();

            $cmsRoot = $this->locateStruxaRoot($extractDir);
            if ($cmsRoot === null) {
                return ['ok' => false, 'message' => 'Archive does not look like Struxa CMS (missing composer.json / public / bootstrap).', 'warnings' => []];
            }
            if (!$this->verifyComposerIdentity($cmsRoot)) {
                return ['ok' => false, 'message' => 'Archive composer.json is not struxa/cms — refusing to apply.', 'warnings' => []];
            }

            $err = $this->mergeTree($cmsRoot, $projectRoot);
            if ($err !== null) {
                return ['ok' => false, 'message' => $err, 'warnings' => []];
            }

            $steps = [
                [
                    'id' => 'merge',
                    'label' => 'Core files merged from package',
                    'status' => 'ok',
                ],
            ];

            $composer = $this->runComposerInstall($projectRoot);
            $composerOut = trim($composer['output']);
            if ($composer['ok']) {
                $steps[] = [
                    'id' => 'composer',
                    'label' => 'Composer (production dependencies)',
                    'status' => 'ok',
                ];
            } else {
                $warnings[] = 'Composer did not finish successfully: ' . $composerOut;
                $warnings[] = 'Run `composer install --no-dev --optimize-autoloader` (or `composer update --no-dev`) on the server, then refresh.';
                $steps[] = [
                    'id' => 'composer',
                    'label' => 'Composer (production dependencies)',
                    'status' => 'warn',
                    'detail' => $composerOut !== '' ? $composerOut : 'Composer exited with an error.',
                ];
            }

            $migrate = $this->runPhpCli($projectRoot, 'bin/migrate.php', []);
            $migrateOut = trim($migrate['output']);
            if ($migrate['ok']) {
                $steps[] = [
                    'id' => 'migrations',
                    'label' => 'Database migrations',
                    'status' => 'ok',
                ];
            } else {
                $warnings[] = 'Migrations: ' . $migrateOut;
                $steps[] = [
                    'id' => 'migrations',
                    'label' => 'Database migrations',
                    'status' => 'warn',
                    'detail' => $migrateOut !== '' ? $migrateOut : 'Migration command failed.',
                ];
            }

            $pluginDeps = $this->runPhpCli($projectRoot, 'bin/plugin-deps.php', ['--no-dev']);
            $pluginOut = trim($pluginDeps['output']);
            if ($pluginDeps['ok']) {
                $steps[] = [
                    'id' => 'plugin_deps',
                    'label' => 'Plugin Composer dependencies',
                    'status' => 'ok',
                ];
            } else {
                $warnings[] = 'Plugin dependencies: ' . $pluginOut;
                $steps[] = [
                    'id' => 'plugin_deps',
                    'label' => 'Plugin Composer dependencies',
                    'status' => 'warn',
                    'detail' => $pluginOut !== '' ? $pluginOut : 'Plugin dependency install failed.',
                ];
            }

            return [
                'ok' => true,
                'message' => 'Update applied to version ' . $latest . '. Reload this panel; if the site misbehaves, restart PHP-FPM or clear OPcache.',
                'warnings' => $warnings,
                'applied_version' => $latest,
                'steps' => $steps,
            ];
        } finally {
            SafeDirectoryRemoval::removeIfInside($work, dirname($work));
        }
    }

    /**
     * Whether Admin → Updates may POST apply. Checks getenv / $_ENV / $_SERVER first, then reads
     * STRUXA_ALLOW_AUTO_UPDATE from the project `.env` file if all runtime values are empty.
     *
     * vlucas/phpdotenv {@see Dotenv::safeLoad()} does not override variables already present in the
     * process environment (even when set to an empty string in php-fpm pool / Apache SetEnv), so a
     * line like STRUXA_ALLOW_AUTO_UPDATE=1 in .env would otherwise be ignored.
     *
     * @param non-empty-string|null $projectRoot Pass the CMS root (same as bootstrap) for .env fallback
     */
    public static function autoUpdateAllowedByEnv(?string $projectRoot = null): bool
    {
        $sources = [
            getenv('STRUXA_ALLOW_AUTO_UPDATE'),
            $_ENV['STRUXA_ALLOW_AUTO_UPDATE'] ?? null,
            $_SERVER['STRUXA_ALLOW_AUTO_UPDATE'] ?? null,
        ];
        foreach ($sources as $raw) {
            if ($raw === false || $raw === null) {
                continue;
            }
            $v = trim((string) $raw);
            if ($v === '') {
                continue;
            }

            return self::envValueMeansEnabled($v);
        }

        $root = $projectRoot ?? dirname(__DIR__, 2);
        $fromFile = self::readDotenvValue($root, 'STRUXA_ALLOW_AUTO_UPDATE');

        return $fromFile !== '' && self::envValueMeansEnabled($fromFile);
    }

    private static function envValueMeansEnabled(string $v): bool
    {
        return in_array(strtolower(trim($v)), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * Minimal .env parser for one key (KEY=value). Does not expand variables.
     */
    private static function readDotenvValue(string $projectRoot, string $key): string
    {
        if ($key === '' || preg_match('/[^A-Z0-9_]/', $key) === 1) {
            return '';
        }
        $path = rtrim($projectRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.env';
        if (!is_file($path) || !is_readable($path)) {
            return '';
        }
        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return '';
        }
        $prefix = $key . '=';
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (!str_starts_with($line, $prefix)) {
                continue;
            }
            $val = trim(substr($line, strlen($prefix)));
            if ($val !== '' && ($val[0] === '"' || $val[0] === "'")) {
                $q = $val[0];
                if (str_ends_with($val, $q) && strlen($val) >= 2) {
                    $val = substr($val, 1, -1);
                }
            }

            return trim($val);
        }

        return '';
    }

    private function verifyComposerIdentity(string $dir): bool
    {
        $path = $dir . DIRECTORY_SEPARATOR . 'composer.json';
        if (!is_readable($path)) {
            return false;
        }
        try {
            /** @var mixed $data */
            $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return false;
        }

        return is_array($data) && (($data['name'] ?? '') === 'struxa/cms');
    }

    private function locateStruxaRoot(string $extractDir): ?string
    {
        $base = realpath($extractDir);
        if ($base === false) {
            return null;
        }
        $candidates = [$base];
        foreach (scandir($base) ?: [] as $n) {
            if ($n === '.' || $n === '..') {
                continue;
            }
            $p = $base . DIRECTORY_SEPARATOR . $n;
            if (is_dir($p)) {
                $candidates[] = $p;
            }
        }
        foreach ($candidates as $dir) {
            if ($this->looksLikeStruxaRoot($dir)) {
                $r = realpath($dir);

                return $r !== false ? $r : null;
            }
        }

        return null;
    }

    private function looksLikeStruxaRoot(string $dir): bool
    {
        return is_file($dir . DIRECTORY_SEPARATOR . 'composer.json')
            && is_file($dir . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'index.php')
            && is_file($dir . DIRECTORY_SEPARATOR . 'bootstrap' . DIRECTORY_SEPARATOR . 'web_app.php');
    }

    private function isMergeExcluded(string $relativeUnix): bool
    {
        $rel = strtolower($relativeUnix);
        if ($rel === '.env' || str_starts_with($rel, '.env/')) {
            return true;
        }
        if (str_starts_with($rel, 'storage/')) {
            return true;
        }
        if (str_starts_with($rel, 'public/uploads/')) {
            return true;
        }
        if ($rel === 'plugins' || str_starts_with($rel, 'plugins/')) {
            return true;
        }
        if (preg_match('#^plugins/[^/]+/vendor($|/)#', $rel) === 1) {
            return true;
        }
        if ($rel === 'vendor' || str_starts_with($rel, 'vendor/')) {
            return true;
        }

        return false;
    }

    private function mergeTree(string $from, string $to): ?string
    {
        $fromReal = realpath($from);
        $toReal = realpath($to);
        if ($fromReal === false || $toReal === false) {
            return 'Path error.';
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            $pathname = $item->getPathname();
            $sub = substr($pathname, strlen($fromReal) + 1);
            $subUnix = str_replace('\\', '/', $sub);
            if ($this->isMergeExcluded($subUnix)) {
                continue;
            }

            $destPath = $toReal . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $subUnix);

            if ($item->isDir()) {
                if (!is_dir($destPath) && !@mkdir($destPath, 0755, true) && !is_dir($destPath)) {
                    return 'Could not create directory: ' . $subUnix;
                }
            } else {
                if ($item->isLink()) {
                    return 'Update archive contains symbolic links (rejected).';
                }
                $parent = dirname($destPath);
                if (!is_dir($parent) && !@mkdir($parent, 0755, true)) {
                    return 'Could not create parent for: ' . $subUnix;
                }
                if (!@copy($pathname, $destPath)) {
                    return 'Could not write file: ' . $subUnix;
                }
            }
        }

        return null;
    }

    private function extractZipSafely(ZipArchive $zip, string $destDir): bool
    {
        $destReal = realpath($destDir);
        if ($destReal === false) {
            return false;
        }
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (!is_string($name) || $name === '') {
                return false;
            }
            if (str_contains($name, '..')) {
                return false;
            }
            $name = str_replace('\\', '/', $name);
            if ($name[0] === '/') {
                return false;
            }
        }

        return $zip->extractTo($destDir);
    }

    /**
     * @return array{body: ?string, detail: string}
     */
    private function fetchUpdateZip(string $url, int $maxBytes): array
    {
        if (!str_starts_with($url, 'https://')) {
            return ['body' => null, 'detail' => 'Download URL must use HTTPS.'];
        }

        $curlDetail = '';
        if (function_exists('curl_init')) {
            $curl = $this->fetchUpdateZipCurl($url, $maxBytes);
            if ($curl['body'] !== null) {
                return $curl;
            }
            $curlDetail = $curl['detail'];
        }

        $fopen = $this->fetchUpdateZipFopen($url, $maxBytes);
        if ($fopen['body'] !== null) {
            return $fopen;
        }

        $parts = [];
        if (!function_exists('curl_init')) {
            $parts[] = 'PHP ext-curl is not loaded.';
        }
        if (!filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
            $parts[] = 'allow_url_fopen is Off.';
        }
        if ($curlDetail !== '') {
            $parts[] = 'cURL: ' . $curlDetail;
        }
        if ($fopen['detail'] !== '') {
            $parts[] = 'URL wrapper: ' . $fopen['detail'];
        }
        if ($parts === []) {
            $parts[] = 'Enable ext-curl or allow_url_fopen for remote ZIP downloads.';
        }

        return ['body' => null, 'detail' => implode(' ', $parts)];
    }

    /**
     * @return array{body: ?string, detail: string}
     */
    private function fetchUpdateZipCurl(string $url, int $maxBytes): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return ['body' => null, 'detail' => 'cURL could not start.'];
        }
        $ua = 'Struxa-SelfUpdate/1.0 (+https://struxapoint.com)';
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_TIMEOUT => 600,
            CURLOPT_USERAGENT => $ua,
            CURLOPT_HTTPHEADER => [
                'Accept: application/zip, application/octet-stream, */*',
            ],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        ]);
        if (defined('CURLOPT_MAXFILESIZE')) {
            curl_setopt($ch, CURLOPT_MAXFILESIZE, $maxBytes + 1);
        }
        $data = curl_exec($ch);
        $errno = curl_errno($ch);
        $err = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($errno !== 0) {
            $hint = $err !== '' ? $err : ('cURL error ' . (string) $errno);

            return ['body' => null, 'detail' => $hint];
        }
        if (!is_string($data)) {
            return ['body' => null, 'detail' => 'Empty or invalid response from server.'];
        }
        if ($code < 200 || $code >= 300) {
            return ['body' => null, 'detail' => 'HTTP status ' . (string) $code . ' from download URL.'];
        }
        if ($data === '' || strlen($data) > $maxBytes) {
            return ['body' => null, 'detail' => $data === '' ? 'Download body was empty.' : 'Download exceeds maximum allowed size.'];
        }

        return ['body' => $data, 'detail' => ''];
    }

    /**
     * @return array{body: ?string, detail: string}
     */
    private function fetchUpdateZipFopen(string $url, int $maxBytes): array
    {
        $ua = 'Struxa-SelfUpdate/1.0 (+https://struxapoint.com)';
        $ctx = stream_context_create([
            'http' => [
                'timeout' => 600,
                'follow_location' => 1,
                'max_redirects' => 10,
                'header' => "User-Agent: {$ua}\r\nAccept: application/zip, application/octet-stream, */*\r\n",
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);
        $h = @fopen($url, 'r', false, $ctx);
        if ($h === false) {
            return ['body' => null, 'detail' => 'fopen URL wrapper failed (check allow_url_fopen and SSL).'];
        }
        $statusLine = $http_response_header[0] ?? '';
        if (!preg_match('#\s2\d\d\s#', $statusLine)) {
            fclose($h);

            return ['body' => null, 'detail' => 'HTTP error: ' . trim((string) $statusLine)];
        }
        $data = '';
        while (!feof($h) && strlen($data) < $maxBytes) {
            $chunk = fread($h, 65_536);
            if ($chunk === false) {
                break;
            }
            $data .= $chunk;
        }
        fclose($h);
        if ($data === '') {
            return ['body' => null, 'detail' => 'Download body was empty.'];
        }
        if (strlen($data) > $maxBytes) {
            return ['body' => null, 'detail' => 'Download exceeds maximum allowed size.'];
        }

        return ['body' => $data, 'detail' => ''];
    }

    /**
     * @param list<string> $args
     * @return array{ok: bool, output: string}
     */
    private function runPhpCli(string $projectRoot, string $relativeScript, array $args): array
    {
        $script = $projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeScript);
        if (!is_file($script)) {
            return ['ok' => false, 'output' => 'Missing ' . $relativeScript];
        }
        $php = $this->resolvePhpCliBinary();
        if ($php === null) {
            return [
                'ok' => false,
                'output' => 'No usable PHP CLI binary. Under PHP-FPM, PHP_BINARY often points at php-fpm, which cannot run CLI scripts. Set STRUXA_PHP_CLI_PATH in .env to your shell `php` (e.g. /usr/bin/php or /usr/local/bin/ea-php82).',
            ];
        }
        $cmd = array_merge([$php, $script], $args);

        return $this->runProcess($projectRoot, $cmd);
    }

    /**
     * CLI binary for proc_open (migrate, plugin-deps). Never use php-fpm.
     *
     * @return non-empty-string|null
     */
    private function resolvePhpCliBinary(): ?string
    {
        $custom = $this->envTrim('STRUXA_PHP_CLI_PATH');
        if ($custom !== '' && is_file($custom) && is_executable($custom)) {
            return $custom;
        }

        $binary = defined('PHP_BINARY') ? (string) PHP_BINARY : '';
        if ($binary !== '' && @is_executable($binary) && !$this->binaryLooksLikePhpFpm($binary)) {
            return $binary;
        }

        foreach (['php', '/usr/bin/php', '/usr/local/bin/php', '/opt/homebrew/bin/php'] as $c) {
            $resolved = $this->resolveComposerBinary($c);
            if ($resolved !== null && !$this->binaryLooksLikePhpFpm($resolved)) {
                return $resolved;
            }
        }

        return null;
    }

    private function binaryLooksLikePhpFpm(string $path): bool
    {
        $base = strtolower(basename($path));

        return str_contains($base, 'php-fpm') || str_contains(strtolower($path), 'php-fpm');
    }

    private function envTrim(string $key): string
    {
        if (isset($_ENV[$key])) {
            $v = trim((string) $_ENV[$key]);
            if ($v !== '') {
                return $v;
            }
        }
        $g = getenv($key);

        return is_string($g) ? trim($g) : '';
    }

    /**
     * @param list<string> $cmd
     * @return array{ok: bool, output: string}
     */
    private function runProcess(string $cwd, array $cmd): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = @proc_open($cmd, $descriptors, $pipes, $cwd, $this->subprocessEnvironment($cwd));
        if (!is_resource($proc)) {
            return ['ok' => false, 'output' => 'Could not start subprocess.'];
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        $combined = trim((string) $stdout . "\n" . (string) $stderr);

        return ['ok' => $code === 0, 'output' => $combined !== '' ? $combined : ('exit ' . (string) $code)];
    }

    /**
     * @return array{ok: bool, output: string}
     */
    private function runComposerInstall(string $projectRoot): array
    {
        $composerBin = null;
        foreach ($this->composerBinaryCandidates() as $c) {
            $resolved = $this->resolveComposerBinary($c);
            if ($resolved !== null) {
                $composerBin = $resolved;
                break;
            }
        }
        if ($composerBin === null) {
            return [
                'ok' => false,
                'output' => 'composer not found for the web user. Set STRUXA_COMPOSER_PATH in .env to the full path (e.g. /home/you/bin/composer).',
            ];
        }

        return $this->runProcess($projectRoot, [
            $composerBin,
            'install',
            '--no-dev',
            '--no-interaction',
            '--no-ansi',
            '--optimize-autoloader',
        ]);
    }

    /**
     * Same resolution order as bin/plugin-deps.php (STRUXA_COMPOSER_PATH, COMPOSER_BIN, PATH, common paths, ~/bin/composer).
     *
     * @return list<string>
     */
    private function composerBinaryCandidates(): array
    {
        $candidates = [];
        foreach (['STRUXA_COMPOSER_PATH', 'COMPOSER_BIN'] as $key) {
            $v = $this->envTrim($key);
            if ($v !== '') {
                $candidates[] = $v;
            }
        }
        $candidates = array_merge($candidates, [
            'composer',
            '/usr/local/bin/composer',
            '/usr/bin/composer',
            '/opt/cpanel/composer/bin/composer',
        ]);
        $home = $this->inferUnixHomeForComposer();
        if ($home !== '') {
            $candidates[] = $home . '/bin/composer';
            $candidates[] = $home . '/.config/composer/vendor/bin/composer';
        }

        return $candidates;
    }

    private function inferUnixHomeForComposer(): string
    {
        $h = $this->envTrim('STRUXA_WEB_HOME');
        if ($h !== '') {
            return $h;
        }
        $g = getenv('HOME');
        if (is_string($g) && trim($g) !== '') {
            return trim($g);
        }
        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $pw = @posix_getpwuid(posix_geteuid());
            if (is_array($pw)) {
                $dir = trim($pw['dir']);
                if ($dir !== '') {
                    return $dir;
                }
            }
        }

        return '';
    }

    /**
     * PHP-FPM often omits HOME; Composer requires HOME or COMPOSER_HOME (Factory.php).
     *
     * @return array<string, string>
     */
    private function subprocessEnvironment(string $projectRoot): array
    {
        $env = [];
        foreach ($_ENV as $k => $v) {
            if (is_string($k) && is_string($v)) {
                $env[$k] = $v;
            }
        }
        foreach ($_SERVER as $k => $v) {
            if (is_string($k) && is_string($v)) {
                $env[$k] = $v;
            }
        }

        foreach (['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASS'] as $dbKey) {
            $g = getenv($dbKey);
            if ($g !== false) {
                $env[$dbKey] = (string) $g;
            }
        }

        $home = trim((string) ($env['HOME'] ?? ''));
        $composerHome = trim((string) ($env['COMPOSER_HOME'] ?? ''));

        if ($this->envTrim('STRUXA_WEB_HOME') !== '') {
            $env['HOME'] = $this->envTrim('STRUXA_WEB_HOME');
            $home = $env['HOME'];
        }
        if ($this->envTrim('STRUXA_COMPOSER_HOME') !== '') {
            $env['COMPOSER_HOME'] = $this->envTrim('STRUXA_COMPOSER_HOME');
            $composerHome = $env['COMPOSER_HOME'];
        }

        $hasHome = $home !== '';
        $hasComposerHome = $composerHome !== '';

        if (!$hasHome && !$hasComposerHome) {
            $derived = $this->deriveHomeFromComposerPath($this->envTrim('STRUXA_COMPOSER_PATH'));
            if ($derived !== null) {
                $env['HOME'] = $derived;
                $hasHome = true;
            }
        }

        if (!$hasHome && !$hasComposerHome && function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $pw = @posix_getpwuid(posix_geteuid());
            if (is_array($pw)) {
                $dir = trim($pw['dir']);
                if ($dir !== '') {
                    $env['HOME'] = $dir;
                    $hasHome = true;
                }
            }
        }

        if (!$hasHome && !$hasComposerHome) {
            $fallback = $projectRoot . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'composer-home';
            if (!is_dir($fallback)) {
                @mkdir($fallback, 0770, true);
            }
            if (is_dir($fallback) && is_writable($fallback)) {
                $env['COMPOSER_HOME'] = $fallback;
                $hasComposerHome = true;
            }
        }

        if (!$hasHome && !$hasComposerHome) {
            $tmp = sys_get_temp_dir();
            if ($tmp !== '' && is_dir($tmp) && is_writable($tmp)) {
                $env['HOME'] = $tmp;
            }
        }

        return $env;
    }

    /**
     * /home/user/bin/composer → /home/user (typical cPanel / manual Composer install).
     *
     * @return non-empty-string|null
     */
    private function deriveHomeFromComposerPath(string $composerBinPath): ?string
    {
        $composerBinPath = trim($composerBinPath);
        if ($composerBinPath === '' || basename($composerBinPath) !== 'composer') {
            return null;
        }
        $binDir = dirname($composerBinPath);
        if (basename($binDir) !== 'bin') {
            return null;
        }
        $home = dirname($binDir);
        if ($home === '' || $home === '.' || $home === DIRECTORY_SEPARATOR) {
            return null;
        }

        return $home;
    }

    /**
     * @return non-empty-string|null
     */
    private function resolveComposerBinary(string $pathOrName): ?string
    {
        if (str_contains($pathOrName, DIRECTORY_SEPARATOR) || str_contains($pathOrName, '/')) {
            return is_file($pathOrName) && is_executable($pathOrName) ? $pathOrName : null;
        }
        $out = [];
        $code = 0;
        @exec('command -v ' . escapeshellarg($pathOrName) . ' 2>/dev/null', $out, $code);
        if ($code !== 0 || !isset($out[0]) || $out[0] === '') {
            return null;
        }
        $resolved = trim($out[0]);

        return ($resolved !== '' && is_executable($resolved)) ? $resolved : null;
    }
}
