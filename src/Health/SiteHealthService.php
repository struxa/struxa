<?php

declare(strict_types=1);

namespace App\Health;

use App\Cache\CacheConfig;
use App\CmsVersion;
use App\Database\Migrator;
use App\Dev\PluginDependencyHealthCheck;
use App\Dev\PluginDependencyHealthIssue;
use App\Plugin\PluginPerformanceRegistry;
use App\Jobs\JobRepository;
use App\Jobs\JobRunTracker;
use App\Maintenance\MaintenanceService;
use App\Media\MediaCompressionSettings;
use App\Publishing\PublishScheduleService;
use App\Publishing\ScheduleRunTracker;
use App\Settings;
use App\Settings\SiteUrlResolver;
use PDO;
use PDOException;
use Throwable;

/**
 * WordPress-style pass/fail health checklist for the admin.
 */
final class SiteHealthService
{
    /** @var list<string> */
    private const REQUIRED_EXTENSIONS = ['mbstring', 'pdo_mysql', 'json', 'curl'];

    /** @var list<string> */
    private const RECOMMENDED_EXTENSIONS = ['gd', 'intl', 'zip'];

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $projectRoot,
    ) {
    }

    /**
     * @param array{
     *   request_is_https?: bool,
     *   site_url?: string,
     *   server_software?: string
     * } $context
     */
    public function report(array $context = []): SiteHealthReport
    {
        $checks = array_merge(
            $this->environmentChecks(),
            $this->storageChecks(),
            $this->databaseChecks(),
            $this->operationsChecks(),
            $this->securityChecks($context),
            $this->pluginChecks(),
            $this->pluginPerformanceChecks(),
        );

        return new SiteHealthReport($checks, $this->infoSnapshot($context));
    }

    /**
     * @return list<SiteHealthCheck>
     */
    private function environmentChecks(): array
    {
        $checks = [];

        $phpOk = version_compare(PHP_VERSION, '8.2.0', '>=');
        $checks[] = new SiteHealthCheck(
            'php_version',
            'PHP version',
            $phpOk ? SiteHealthStatus::GOOD : SiteHealthStatus::CRITICAL,
            $phpOk
                ? 'PHP ' . PHP_VERSION . ' meets the 8.2+ requirement.'
                : 'PHP ' . PHP_VERSION . ' is below the required 8.2.',
            'environment',
        );

        $missingRequired = [];
        foreach (self::REQUIRED_EXTENSIONS as $ext) {
            if (!extension_loaded($ext)) {
                $missingRequired[] = $ext;
            }
        }
        $checks[] = new SiteHealthCheck(
            'php_extensions_required',
            'Required PHP extensions',
            $missingRequired === [] ? SiteHealthStatus::GOOD : SiteHealthStatus::CRITICAL,
            $missingRequired === []
                ? 'mbstring, PDO MySQL, JSON, and cURL are available.'
                : 'Missing required extension(s): ' . implode(', ', $missingRequired) . '.',
            'environment',
        );

        $missingRecommended = [];
        foreach (self::RECOMMENDED_EXTENSIONS as $ext) {
            if ($ext === 'gd') {
                continue;
            }
            if (!extension_loaded($ext)) {
                $missingRecommended[] = $ext;
            }
        }
        if (!extension_loaded('gd') && !extension_loaded('imagick')) {
            $missingRecommended[] = 'gd or imagick';
        }
        $checks[] = new SiteHealthCheck(
            'php_extensions_recommended',
            'Recommended PHP extensions',
            $missingRecommended === [] ? SiteHealthStatus::GOOD : SiteHealthStatus::RECOMMENDED,
            $missingRecommended === []
                ? 'Optional extensions for media, i18n, and ZIP exports are present.'
                : 'Consider enabling: ' . implode(', ', $missingRecommended) . '.',
            'environment',
        );

        $vendorOk = is_file($this->projectRoot . '/vendor/autoload.php');
        $checks[] = new SiteHealthCheck(
            'composer_vendor',
            'Composer dependencies',
            $vendorOk ? SiteHealthStatus::GOOD : SiteHealthStatus::CRITICAL,
            $vendorOk
                ? 'vendor/autoload.php is present.'
                : 'vendor/autoload.php is missing. Run composer install on the server.',
            'environment',
        );

        return $checks;
    }

    /**
     * @return list<SiteHealthCheck>
     */
    private function storageChecks(): array
    {
        $paths = [
            'storage' => $this->projectRoot . '/storage',
            'storage/cache' => $this->projectRoot . '/storage/cache',
            'public/uploads' => $this->projectRoot . '/public/uploads',
        ];

        $failed = [];
        foreach ($paths as $label => $path) {
            if (!is_dir($path) || !is_writable($path)) {
                $failed[] = $label;
            }
        }

        return [
            new SiteHealthCheck(
                'writable_directories',
                'Writable directories',
                $failed === [] ? SiteHealthStatus::GOOD : SiteHealthStatus::CRITICAL,
                $failed === []
                    ? 'storage/, storage/cache/, and public/uploads/ are writable.'
                    : 'These paths must exist and be writable: ' . implode(', ', $failed) . '.',
                'storage',
                null,
                'admin.tools.site_health',
                'Refresh status',
            ),
        ];
    }

    /**
     * @return list<SiteHealthCheck>
     */
    private function databaseChecks(): array
    {
        $checks = [];

        try {
            $this->pdo->query('SELECT 1');
            $checks[] = new SiteHealthCheck(
                'database_connection',
                'Database connection',
                SiteHealthStatus::GOOD,
                'Connected to MySQL successfully.',
                'database',
            );
        } catch (PDOException $e) {
            $checks[] = new SiteHealthCheck(
                'database_connection',
                'Database connection',
                SiteHealthStatus::CRITICAL,
                'Could not query the database: ' . $e->getMessage(),
                'database',
            );

            return $checks;
        }

        $migrator = new Migrator($this->pdo, $this->projectRoot . '/database/migrations');
        $pending = $migrator->pending();
        $checks[] = new SiteHealthCheck(
            'database_migrations',
            'Database migrations',
            $pending === [] ? SiteHealthStatus::GOOD : SiteHealthStatus::CRITICAL,
            $pending === []
                ? 'All migration files have been applied.'
                : count($pending) . ' pending migration(s): ' . implode(', ', array_slice($pending, 0, 3))
                    . (count($pending) > 3 ? '…' : '') . '. Run composer migrate.',
            'database',
            $pending !== [] ? implode("\n", $pending) : null,
        );

        try {
            $mb = (float) $this->pdo->query(
                'SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 1)
                 FROM information_schema.tables WHERE table_schema = DATABASE()'
            )->fetchColumn();
            $checks[] = new SiteHealthCheck(
                'database_size',
                'Database size',
                SiteHealthStatus::GOOD,
                'Approximate schema size: ' . ($mb > 0 ? $mb . ' MB' : 'under 1 MB') . '.',
                'database',
            );
        } catch (PDOException) {
            // Informational only; skip if unavailable.
        }

        return $checks;
    }

    /**
     * @return list<SiteHealthCheck>
     */
    private function operationsChecks(): array
    {
        $checks = [];
        $maintenance = new MaintenanceService($this->pdo, $this->projectRoot);
        $stats = $maintenance->stats();

        $expiredTokens = (int) ($stats['preview_tokens_expired'] ?? 0);
        $checks[] = new SiteHealthCheck(
            'preview_tokens',
            'Expired preview tokens',
            $expiredTokens > 500 ? SiteHealthStatus::RECOMMENDED : SiteHealthStatus::GOOD,
            $expiredTokens === 0
                ? 'No expired stakeholder preview tokens waiting to be purged.'
                : $expiredTokens . ' expired preview token(s) can be purged safely.',
            'operations',
            null,
            $expiredTokens > 0 ? 'admin.tools.maintenance' : null,
            $expiredTokens > 0 ? 'Open maintenance' : null,
        );

        $lastRun = ScheduleRunTracker::lastRunAt();
        $overdue = (new PublishScheduleService($this->pdo))->countOverdueScheduled();
        if ($overdue > 0) {
            $scheduleStatus = SiteHealthStatus::CRITICAL;
            $scheduleMessage = $overdue . ' scheduled publish/unpublish action(s) are overdue. Run php bin/cms.php schedule:run on a cron timer.';
        } elseif ($lastRun === null) {
            $scheduleStatus = SiteHealthStatus::RECOMMENDED;
            $scheduleMessage = 'No schedule runner heartbeat recorded yet. Web traffic triggers occasional runs, but cron is more reliable.';
        } else {
            $ageHours = (time() - strtotime($lastRun . ' UTC')) / 3600;
            if ($ageHours > 48) {
                $scheduleStatus = SiteHealthStatus::RECOMMENDED;
                $scheduleMessage = 'Last schedule run was ' . round($ageHours) . ' hours ago. Consider a cron job for php bin/cms.php schedule:run.';
            } else {
                $scheduleStatus = SiteHealthStatus::GOOD;
                $scheduleMessage = 'Schedule runner last recorded at ' . $lastRun . ' UTC.';
            }
        }
        $checks[] = new SiteHealthCheck(
            'schedule_runner',
            'Scheduled publish runner',
            $scheduleStatus,
            $scheduleMessage,
            'operations',
            $lastRun !== null ? 'Last run (UTC): ' . $lastRun : 'Set a cron job: */15 * * * * php bin/cms.php schedule:run',
        );

        $cacheEnabled = CacheConfig::publicCacheEnabled();
        $checks[] = new SiteHealthCheck(
            'public_page_cache',
            'Public page cache',
            $cacheEnabled ? SiteHealthStatus::GOOD : SiteHealthStatus::RECOMMENDED,
            $cacheEnabled
                ? 'Public page cache is enabled (TTL ' . CacheConfig::publicTtlSeconds() . 's).'
                : 'Public page cache is off. Enable it on Performance for faster guest traffic.',
            'operations',
            null,
            'admin.tools.cache',
            'Performance settings',
        );

        $caps = MediaCompressionSettings::capabilities();
        if (!$caps['available']) {
            $checks[] = new SiteHealthCheck(
                'media_compression',
                'Upload image compression',
                SiteHealthStatus::RECOMMENDED,
                (string) $caps['hint'],
                'operations',
            );
        }

        $jobRepo = new JobRepository($this->pdo);
        if ($jobRepo->tableExists()) {
            $jobCounts = $jobRepo->counts();
            $failedJobs = (int) ($jobCounts['failed'] ?? 0);
            $pendingJobs = (int) ($jobCounts['pending'] ?? 0);
            $lastWorker = JobRunTracker::lastRunAt();

            if ($failedJobs > 0) {
                $jobStatus = SiteHealthStatus::RECOMMENDED;
                $jobMessage = $failedJobs . ' background job(s) failed. Check Speed & maintenance → Background jobs.';
            } elseif ($pendingJobs > 0 && $lastWorker === null) {
                $jobStatus = SiteHealthStatus::RECOMMENDED;
                $jobMessage = $pendingJobs . ' job(s) pending but the worker has never run. Add cron: php bin/cms.php jobs:work';
            } elseif ($pendingJobs > 50) {
                $jobStatus = SiteHealthStatus::RECOMMENDED;
                $jobMessage = $pendingJobs . ' jobs waiting in queue. Ensure jobs:work runs on a schedule.';
            } elseif ($pendingJobs > 0 && $lastWorker !== null) {
                $workerAgeHours = (time() - strtotime($lastWorker . ' UTC')) / 3600;
                if ($workerAgeHours > 48) {
                    $jobStatus = SiteHealthStatus::RECOMMENDED;
                    $jobMessage = 'Worker last ran ' . round($workerAgeHours) . ' hours ago with ' . $pendingJobs . ' pending job(s).';
                } else {
                    $jobStatus = SiteHealthStatus::GOOD;
                    $jobMessage = $pendingJobs . ' job(s) pending; worker ran at ' . $lastWorker . ' UTC.';
                }
            } else {
                $jobStatus = SiteHealthStatus::GOOD;
                $jobMessage = $lastWorker !== null
                    ? 'Background job queue is idle. Worker last ran at ' . $lastWorker . ' UTC.'
                    : 'Background job queue is idle. Use jobs:dispatch + jobs:work on cron for deferred tasks.';
            }

            $checks[] = new SiteHealthCheck(
                'background_jobs',
                'Background job queue',
                $jobStatus,
                $jobMessage,
                'operations',
                null,
                'admin.tools.maintenance',
                $failedJobs > 0 || $pendingJobs > 0 ? 'Open maintenance' : null,
            );
        }

        return $checks;
    }

    /**
     * @param array{
     *   request_is_https?: bool,
     *   site_url?: string,
     *   server_software?: string
     * } $context
     *
     * @return list<SiteHealthCheck>
     */
    private function securityChecks(array $context): array
    {
        $checks = [];
        $siteUrl = trim((string) ($context['site_url'] ?? SiteUrlResolver::resolve()));
        $siteIsHttps = str_starts_with(strtolower($siteUrl), 'https://');
        $requestHttps = (bool) ($context['request_is_https'] ?? false);

        if ($siteIsHttps || $requestHttps) {
            $cookieSecure = in_array(strtolower(trim((string) ($_ENV['PHPAUTH_COOKIE_SECURE'] ?? '0'))), ['1', 'true', 'yes'], true);
            $checks[] = new SiteHealthCheck(
                'https_cookie_secure',
                'Secure session cookies',
                $cookieSecure ? SiteHealthStatus::GOOD : SiteHealthStatus::RECOMMENDED,
                $cookieSecure
                    ? 'PHPAUTH_COOKIE_SECURE is enabled for HTTPS.'
                    : 'Site URL uses HTTPS but PHPAUTH_COOKIE_SECURE is not set to 1.',
                'security',
            );
        } else {
            $checks[] = new SiteHealthCheck(
                'https',
                'HTTPS',
                SiteHealthStatus::RECOMMENDED,
                'PHPAUTH_SITE_URL is not HTTPS. Use TLS in production.',
                'security',
            );
        }

        $debugOn = in_array(strtolower(trim((string) ($_ENV['APP_DEBUG'] ?? ''))), ['1', 'true', 'yes'], true);
        $checks[] = new SiteHealthCheck(
            'app_debug',
            'Debug mode',
            $debugOn ? SiteHealthStatus::CRITICAL : SiteHealthStatus::GOOD,
            $debugOn
                ? 'APP_DEBUG is enabled. Disable it in production to hide stack traces.'
                : 'APP_DEBUG is off (or unset).',
            'security',
        );

        $installExposed = is_file($this->projectRoot . '/public/install.php');
        $checks[] = new SiteHealthCheck(
            'install_php',
            'Web installer',
            $installExposed ? SiteHealthStatus::RECOMMENDED : SiteHealthStatus::GOOD,
            $installExposed
                ? 'public/install.php is still present. Remove or protect it after setup.'
                : 'public/install.php is not present (good for production).',
            'security',
        );

        return $checks;
    }

    /**
     * @return list<SiteHealthCheck>
     */
    private function pluginChecks(): array
    {
        try {
            $issues = (new PluginDependencyHealthCheck($this->projectRoot))->run(true, true);
        } catch (Throwable) {
            return [
                new SiteHealthCheck(
                    'plugins',
                    'Active plugins',
                    SiteHealthStatus::RECOMMENDED,
                    'Could not scan plugin dependencies.',
                    'plugins',
                ),
            ];
        }

        if ($issues === []) {
            return [
                new SiteHealthCheck(
                    'plugins',
                    'Active plugins',
                    SiteHealthStatus::GOOD,
                    'No dependency or autoload issues in active plugins.',
                    'plugins',
                    null,
                    'admin.extensions.plugins.index',
                    'Manage plugins',
                ),
            ];
        }

        $errors = array_filter($issues, static fn (PluginDependencyHealthIssue $i): bool => $i->isError());
        $status = $errors !== [] ? SiteHealthStatus::CRITICAL : SiteHealthStatus::RECOMMENDED;
        $lines = array_map(static fn (PluginDependencyHealthIssue $i): string => $i->formatLine(), $issues);

        return [
            new SiteHealthCheck(
                'plugins',
                'Active plugins',
                $status,
                count($errors) . ' error(s), ' . (count($issues) - count($errors)) . ' warning(s) in active plugins.',
                'plugins',
                implode("\n", $lines),
                'admin.extensions.plugins.index',
                'Manage plugins',
            ),
        ];
    }

    /**
     * @return list<SiteHealthCheck>
     */
    private function pluginPerformanceChecks(): array
    {
        try {
            $snapshots = PluginPerformanceRegistry::instance()->allSnapshots();
        } catch (Throwable) {
            return [];
        }

        if ($snapshots === []) {
            return [];
        }

        $slowBoot = [];
        $slowHooks = [];
        $bootErrors = [];
        foreach ($snapshots as $slug => $row) {
            if (!is_array($row)) {
                continue;
            }
            $bootMs = $row['last_boot_ms'] ?? null;
            if (is_numeric($bootMs) && (float) $bootMs >= PluginPerformanceRegistry::BOOT_SLOW_MS) {
                $slowBoot[] = $slug . ' (' . $bootMs . ' ms)';
            }
            if (!empty($row['last_boot_error']) && is_array($row['last_boot_error'])) {
                $bootErrors[] = $slug;
            }
            $hooks = $row['slow_hooks'] ?? [];
            if (is_array($hooks) && $hooks !== []) {
                $slowHooks[] = $slug;
            }
        }

        if ($slowBoot === [] && $slowHooks === [] && $bootErrors === []) {
            return [
                new SiteHealthCheck(
                    'plugin_performance',
                    'Plugin performance',
                    SiteHealthStatus::GOOD,
                    'No slow plugin boot or hook timings recorded.',
                    'plugins',
                    null,
                    'admin.extensions.plugins.index',
                    'Manage plugins',
                ),
            ];
        }

        $lines = [];
        if ($slowBoot !== []) {
            $lines[] = 'Slow boot: ' . implode(', ', $slowBoot);
        }
        if ($slowHooks !== []) {
            $lines[] = 'Slow hooks: ' . implode(', ', $slowHooks);
        }
        if ($bootErrors !== []) {
            $lines[] = 'Boot errors: ' . implode(', ', $bootErrors);
        }

        return [
            new SiteHealthCheck(
                'plugin_performance',
                'Plugin performance',
                SiteHealthStatus::RECOMMENDED,
                count($slowBoot) + count($slowHooks) + count($bootErrors) . ' plugin performance issue(s) detected.',
                'plugins',
                implode("\n", $lines),
                'admin.extensions.plugins.index',
                'Review plugins',
            ),
        ];
    }

    /**
     * @param array{
     *   request_is_https?: bool,
     *   site_url?: string,
     *   server_software?: string
     * } $context
     *
     * @return array<string, string>
     */
    private function infoSnapshot(array $context): array
    {
        $info = [
            'CMS version' => CmsVersion::CURRENT,
            'PHP' => PHP_VERSION,
            'Memory limit' => (string) ini_get('memory_limit'),
            'Max upload (PHP)' => min(
                self::iniBytes('upload_max_filesize'),
                self::iniBytes('post_max_size')
            ) > 0
                ? round(min(self::iniBytes('upload_max_filesize'), self::iniBytes('post_max_size')) / 1024 / 1024, 1) . ' MB'
                : '—',
            'Site URL' => trim((string) ($context['site_url'] ?? SiteUrlResolver::resolve())) ?: '—',
        ];

        if (($context['server_software'] ?? '') !== '') {
            $info['Server'] = (string) $context['server_software'];
        }

        try {
            $info['MySQL'] = (string) $this->pdo->query('SELECT VERSION()')->fetchColumn();
        } catch (PDOException) {
            $info['MySQL'] = 'Unavailable';
        }

        $info['Public cache'] = CacheConfig::publicCacheEnabled() ? 'On' : 'Off';
        $info['Maintenance auto-purge'] = Settings::get('maintenance_auto_purge', '0') === '1' ? 'On' : 'Off';
        $info['Job worker (UTC)'] = JobRunTracker::lastRunAt() ?? 'Never';

        return $info;
    }

    private static function iniBytes(string $key): int
    {
        $raw = ini_get($key);
        if (!is_string($raw) || $raw === '') {
            return 0;
        }
        $raw = trim($raw);
        if (ctype_digit($raw)) {
            return (int) $raw;
        }
        $unit = strtolower(substr($raw, -1));
        $num = (float) substr($raw, 0, -1);

        return (int) match ($unit) {
            'g' => $num * 1024 * 1024 * 1024,
            'm' => $num * 1024 * 1024,
            'k' => $num * 1024,
            default => (float) $raw,
        };
    }
}
