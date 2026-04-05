<?php

declare(strict_types=1);

namespace App\Update;

use App\CmsVersion;
use App\Filesystem\SafeDirectoryRemoval;
use App\Theme\ThemeRemoteInstaller;
use ZipArchive;

/**
 * Applies a remote Struxa ZIP (GitHub archive or vendor-built package) over the install.
 * Preserves .env, storage/, public/uploads/, and each plugin's vendor/ tree.
 */
final class CmsSelfUpdater
{
    private const MAX_ZIP_BYTES = 120_000_000;

    /**
     * @param array<string, mixed> $status CmsUpdateChecker::check() result
     * @return array{ok: bool, message: string, warnings: list<string>}
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

        $body = $this->httpGetLimited($url, self::MAX_ZIP_BYTES);
        if ($body === null) {
            return ['ok' => false, 'message' => 'Could not download the update package (size, network, or timeout).', 'warnings' => []];
        }

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

            $composer = $this->runComposerInstall($projectRoot);
            if (!$composer['ok']) {
                $warnings[] = 'Composer did not finish successfully: ' . trim($composer['output']);
                $warnings[] = 'Run `composer install --no-dev --optimize-autoloader` (or `composer update --no-dev`) on the server, then refresh.';
            }

            $migrate = $this->runPhpCli($projectRoot, 'bin/migrate.php', []);
            if (!$migrate['ok']) {
                $warnings[] = 'Migrations: ' . trim($migrate['output']);
            }

            $pluginDeps = $this->runPhpCli($projectRoot, 'bin/plugin-deps.php', ['--no-dev']);
            if (!$pluginDeps['ok']) {
                $warnings[] = 'Plugin dependencies: ' . trim($pluginDeps['output']);
            }

            return [
                'ok' => true,
                'message' => 'Update applied to version ' . $latest . '. Reload this panel; if the site misbehaves, restart PHP-FPM or clear OPcache.',
                'warnings' => $warnings,
            ];
        } finally {
            SafeDirectoryRemoval::removeIfInside($work, dirname($work));
        }
    }

    public static function autoUpdateAllowedByEnv(): bool
    {
        if (isset($_ENV['STRUXA_ALLOW_AUTO_UPDATE'])) {
            $v = trim((string) $_ENV['STRUXA_ALLOW_AUTO_UPDATE']);
        } else {
            $g = getenv('STRUXA_ALLOW_AUTO_UPDATE');
            $v = $g === false ? '' : trim($g);
        }

        return in_array(strtolower($v), ['1', 'true', 'yes', 'on'], true);
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

    private function httpGetLimited(string $url, int $maxBytes): ?string
    {
        $ctx = stream_context_create([
            'http' => [
                'timeout' => 180,
                'follow_location' => 1,
                'max_redirects' => 10,
                'header' => "User-Agent: Struxa-SelfUpdate/1.0\r\n",
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
            $chunk = fread($h, 65_536);
            if ($chunk === false) {
                break;
            }
            $data .= $chunk;
        }
        fclose($h);
        if (strlen($data) >= $maxBytes || $data === '') {
            return null;
        }

        return $data;
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
        $php = PHP_BINARY;
        if (!@is_executable($php)) {
            $php = 'php';
        }
        $cmd = array_merge([$php, $script], $args);

        return $this->runProcess($projectRoot, $cmd);
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
        $proc = @proc_open($cmd, $descriptors, $pipes, $cwd, null);
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
        if (isset($_ENV['STRUXA_COMPOSER_PATH'])) {
            $custom = trim((string) $_ENV['STRUXA_COMPOSER_PATH']);
        } else {
            $g = getenv('STRUXA_COMPOSER_PATH');
            $custom = $g === false ? '' : trim($g);
        }
        $candidates = $custom !== '' ? [$custom] : ['composer', '/usr/local/bin/composer', '/usr/bin/composer'];
        $composerBin = null;
        foreach ($candidates as $c) {
            $resolved = $this->resolveComposerBinary($c);
            if ($resolved !== null) {
                $composerBin = $resolved;
                break;
            }
        }
        if ($composerBin === null) {
            return ['ok' => false, 'output' => 'composer not found in PATH (set STRUXA_COMPOSER_PATH).'];
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
