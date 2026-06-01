<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Publishing\PublishScheduleService;
use App\Publishing\ScheduleRunTracker;
use PDO;

/**
 * Cron / CLI heartbeat snapshot for the admin jobs monitor.
 */
final class CronVisibility
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly JobRepository $jobs,
    ) {
    }

    /**
     * @return array{
     *     schedule_last_run_at: ?string,
     *     jobs_last_worker_at: ?string,
     *     schedule_overdue_count: int,
     *     job_counts: array{pending: int, running: int, failed: int, completed_24h: int},
     *     schedule_health: string,
     *     worker_health: string,
     *     cron_examples: list<array{label: string, command: string}>
     * }
     */
    public function snapshot(): array
    {
        $scheduleLast = ScheduleRunTracker::lastRunAt();
        $workerLast = JobRunTracker::lastRunAt();
        $overdue = (new PublishScheduleService($this->pdo))->countOverdueScheduled();
        $counts = $this->jobs->tableExists() ? $this->jobs->counts() : [
            'pending' => 0,
            'running' => 0,
            'failed' => 0,
            'completed_24h' => 0,
        ];

        return [
            'schedule_last_run_at' => $scheduleLast,
            'jobs_last_worker_at' => $workerLast,
            'schedule_overdue_count' => $overdue,
            'job_counts' => $counts,
            'schedule_health' => $this->scheduleHealth($scheduleLast, $overdue),
            'worker_health' => $this->workerHealth($workerLast, $counts),
            'cron_examples' => [
                [
                    'label' => 'Scheduled publish + optional retention (direct)',
                    'command' => '*/15 * * * * php bin/cms.php schedule:run',
                ],
                [
                    'label' => 'Background queue (recommended)',
                    'command' => '*/15 * * * * php bin/cms.php jobs:dispatch && php bin/cms.php jobs:work --limit=20',
                ],
            ],
        ];
    }

    public static function ageDescription(?string $utcDatetime): string
    {
        if ($utcDatetime === null || trim($utcDatetime) === '') {
            return 'never';
        }
        $ts = strtotime($utcDatetime . ' UTC');
        if ($ts === false) {
            return $utcDatetime;
        }
        $diff = time() - $ts;
        if ($diff < 60) {
            return 'just now';
        }
        if ($diff < 3600) {
            $m = (int) round($diff / 60);

            return $m . ' min ago';
        }
        if ($diff < 86400 * 2) {
            $h = (int) round($diff / 3600);

            return $h . ' h ago';
        }
        $d = (int) round($diff / 86400);

        return $d . ' d ago';
    }

    /**
     * @param array{pending: int, running: int, failed: int, completed_24h: int} $counts
     */
    private function workerHealth(?string $lastRun, array $counts): string
    {
        $pending = (int) ($counts['pending'] ?? 0);
        $failed = (int) ($counts['failed'] ?? 0);
        if ($failed > 0) {
            return 'critical';
        }
        if ($pending > 0 && $lastRun === null) {
            return 'critical';
        }
        if ($pending > 50) {
            return 'warn';
        }
        if ($lastRun === null) {
            return 'warn';
        }
        $ageHours = (time() - strtotime($lastRun . ' UTC')) / 3600;
        if ($pending > 0 && $ageHours > 48) {
            return 'warn';
        }
        if ($ageHours > 168) {
            return 'warn';
        }

        return 'good';
    }

    private function scheduleHealth(?string $lastRun, int $overdue): string
    {
        if ($overdue > 0) {
            return 'critical';
        }
        if ($lastRun === null) {
            return 'warn';
        }
        $ageHours = (time() - strtotime($lastRun . ' UTC')) / 3600;
        if ($ageHours > 48) {
            return 'warn';
        }

        return 'good';
    }
}
