<?php

declare(strict_types=1);

namespace App\Jobs;

final class JobWorker
{
    public function __construct(
        private readonly JobRepository $repository,
        private readonly JobHandlerRegistry $handlers,
        private readonly JobHandlerContext $context,
    ) {
    }

    /**
     * @return array{processed: int, succeeded: int, failed: int, released_stale: int, messages: list<string>}
     */
    public function run(string $queue = 'default', int $limit = 10, string $workerId = 'cli'): array
    {
        $limit = max(1, min(100, $limit));
        $report = [
            'processed' => 0,
            'succeeded' => 0,
            'failed' => 0,
            'released_stale' => 0,
            'messages' => [],
        ];

        if (!$this->repository->tableExists()) {
            $report['messages'][] = 'cms_jobs table missing — run php bin/cms.php migrate.';

            return $report;
        }

        $report['released_stale'] = $this->repository->releaseStale();

        for ($i = 0; $i < $limit; ++$i) {
            $job = $this->repository->claimNext($queue, $workerId);
            if ($job === null) {
                break;
            }

            ++$report['processed'];
            $result = $this->handlers->handle($job, $this->context);
            $ok = ($result['ok'] ?? false) === true;
            $message = trim((string) ($result['message'] ?? ''));

            if ($ok) {
                $this->repository->markCompleted($job->id, $message !== '' ? $message : null);
                ++$report['succeeded'];
                $report['messages'][] = "#{$job->id} {$job->type}: " . ($message !== '' ? $message : 'OK');

                $chain = $result['chain'] ?? [];
                if (is_array($chain) && $chain !== []) {
                    $this->context->queue->enqueueMany($chain, $queue);
                }
            } else {
                $retry = ($result['retry'] ?? false) === true;
                $failMsg = $message !== '' ? $message : 'Job failed.';
                $this->repository->markFailed($job->id, $failMsg, $retry);
                ++$report['failed'];
                $suffix = $retry ? ' (will retry)' : '';
                $report['messages'][] = "#{$job->id} {$job->type}: {$failMsg}{$suffix}";
            }
        }

        if ($report['processed'] > 0) {
            JobRunTracker::record($this->context->pdo);
        }

        return $report;
    }
}
