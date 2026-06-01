<?php

declare(strict_types=1);

namespace App\Jobs;

use PDO;
use PDOException;
use RuntimeException;

final class JobRepository
{
    public function __construct(
        private readonly PDO $pdo,
    ) {
    }

    public function tableExists(): bool
    {
        try {
            $check = $this->pdo->query("SHOW TABLES LIKE 'cms_jobs'");

            return $check !== false && $check->rowCount() > 0;
        } catch (PDOException) {
            return false;
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function enqueue(
        string $type,
        array $payload = [],
        ?string $dedupeKey = null,
        string $queue = 'default',
        int $delaySeconds = 0,
        int $maxAttempts = 3,
    ): int {
        if ($dedupeKey !== null && $dedupeKey !== '') {
            $existing = $this->findActiveByDedupeKey($dedupeKey);
            if ($existing !== null) {
                return $existing->id;
            }
        }

        $payloadJson = json_encode($payload, JSON_THROW_ON_ERROR);
        $delaySeconds = max(0, $delaySeconds);
        $availableExpr = $delaySeconds > 0
            ? 'DATE_ADD(UTC_TIMESTAMP(), INTERVAL ' . $delaySeconds . ' SECOND)'
            : 'UTC_TIMESTAMP()';

        $sql = 'INSERT INTO cms_jobs (queue, type, payload, status, available_at, max_attempts, dedupe_key, created_at, updated_at)
                VALUES (:queue, :type, :payload, :status, ' . $availableExpr . ', :max_attempts, :dedupe_key, UTC_TIMESTAMP(), UTC_TIMESTAMP())';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'queue' => $queue,
            'type' => $type,
            'payload' => $payloadJson,
            'status' => JobStatus::PENDING,
            'max_attempts' => max(1, min(10, $maxAttempts)),
            'dedupe_key' => $dedupeKey,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function findById(int $id): ?Job
    {
        $stmt = $this->pdo->prepare('SELECT * FROM cms_jobs WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? Job::fromRow($row) : null;
    }

    public function findActiveByDedupeKey(string $dedupeKey): ?Job
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM cms_jobs
             WHERE dedupe_key = :key AND status IN (:pending, :running)
             ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([
            'key' => $dedupeKey,
            'pending' => JobStatus::PENDING,
            'running' => JobStatus::RUNNING,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? Job::fromRow($row) : null;
    }

    public function claimNext(string $queue, string $workerId): ?Job
    {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                'SELECT id FROM cms_jobs
                 WHERE queue = :queue AND status = :pending AND available_at <= UTC_TIMESTAMP()
                 ORDER BY available_at ASC, id ASC
                 LIMIT 1
                 FOR UPDATE'
            );
            $stmt->execute([
                'queue' => $queue,
                'pending' => JobStatus::PENDING,
            ]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                $this->pdo->commit();

                return null;
            }

            $id = (int) $row['id'];
            $upd = $this->pdo->prepare(
                'UPDATE cms_jobs
                 SET status = :running, reserved_at = UTC_TIMESTAMP(), reserved_by = :worker,
                     attempts = attempts + 1, updated_at = UTC_TIMESTAMP()
                 WHERE id = :id AND status = :pending'
            );
            $upd->execute([
                'running' => JobStatus::RUNNING,
                'worker' => mb_substr($workerId, 0, 64),
                'id' => $id,
                'pending' => JobStatus::PENDING,
            ]);
            if ($upd->rowCount() === 0) {
                $this->pdo->rollBack();

                return null;
            }

            $this->pdo->commit();

            return $this->findById($id);
        } catch (PDOException $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw new RuntimeException('Could not claim job: ' . $e->getMessage(), 0, $e);
        }
    }

    public function markCompleted(int $id, ?string $summary = null): void
    {
        $summary = $summary !== null ? mb_substr($summary, 0, 512) : null;
        $stmt = $this->pdo->prepare(
            'UPDATE cms_jobs
             SET status = :completed, result_summary = :summary, last_error = NULL,
                 completed_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP()
             WHERE id = :id'
        );
        $stmt->execute([
            'completed' => JobStatus::COMPLETED,
            'summary' => $summary,
            'id' => $id,
        ]);
    }

    public function markFailed(int $id, string $error, bool $retry): void
    {
        $job = $this->findById($id);
        if ($job === null) {
            return;
        }

        $error = mb_substr($error, 0, 65535);
        if ($retry && $job->attempts < $job->maxAttempts) {
            $delay = min(3600, 30 * (2 ** max(0, $job->attempts - 1)));
            $stmt = $this->pdo->prepare(
                'UPDATE cms_jobs
                 SET status = :pending, last_error = :error, reserved_at = NULL, reserved_by = NULL,
                     available_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL ' . $delay . ' SECOND),
                     updated_at = UTC_TIMESTAMP()
                 WHERE id = :id'
            );
            $stmt->execute([
                'pending' => JobStatus::PENDING,
                'error' => $error,
                'id' => $id,
            ]);

            return;
        }

        $stmt = $this->pdo->prepare(
            'UPDATE cms_jobs
             SET status = :failed, last_error = :error, completed_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP()
             WHERE id = :id'
        );
        $stmt->execute([
            'failed' => JobStatus::FAILED,
            'error' => $error,
            'id' => $id,
        ]);
    }

    public function releaseStale(int $timeoutSeconds = 900): int
    {
        $timeoutSeconds = max(60, $timeoutSeconds);
        $stmt = $this->pdo->prepare(
            'UPDATE cms_jobs
             SET status = :pending, reserved_at = NULL, reserved_by = NULL,
                 available_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP(),
                 last_error = CONCAT(COALESCE(last_error, ""), "\nRecovered from stale running state.")
             WHERE status = :running
               AND reserved_at IS NOT NULL
               AND reserved_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL ' . $timeoutSeconds . ' SECOND)'
        );
        $stmt->execute([
            'pending' => JobStatus::PENDING,
            'running' => JobStatus::RUNNING,
        ]);

        return $stmt->rowCount();
    }

    /**
     * @return array{pending: int, running: int, failed: int, completed_24h: int}
     */
    public function counts(): array
    {
        if (!$this->tableExists()) {
            return ['pending' => 0, 'running' => 0, 'failed' => 0, 'completed_24h' => 0];
        }

        try {
            $pending = $this->scalar("SELECT COUNT(*) FROM cms_jobs WHERE status = '" . JobStatus::PENDING . "'");
            $running = $this->scalar("SELECT COUNT(*) FROM cms_jobs WHERE status = '" . JobStatus::RUNNING . "'");
            $failed = $this->scalar("SELECT COUNT(*) FROM cms_jobs WHERE status = '" . JobStatus::FAILED . "'");
            $completed24 = $this->scalar(
                "SELECT COUNT(*) FROM cms_jobs WHERE status = '" . JobStatus::COMPLETED . "'
                 AND completed_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 24 HOUR)"
            );

            return [
                'pending' => $pending,
                'running' => $running,
                'failed' => $failed,
                'completed_24h' => $completed24,
            ];
        } catch (PDOException) {
            return ['pending' => 0, 'running' => 0, 'failed' => 0, 'completed_24h' => 0];
        }
    }

    /**
     * @return list<Job>
     */
    public function listRecent(int $limit = 15): array
    {
        return $this->listFiltered(null, null, '', 1, $limit)['items'];
    }

    /**
     * @return array{items: list<Job>, total: int}
     */
    public function listFiltered(
        ?string $status = null,
        ?string $type = null,
        string $queue = '',
        int $page = 1,
        int $perPage = 25,
    ): array {
        if (!$this->tableExists()) {
            return ['items' => [], 'total' => 0];
        }

        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;
        [$where, $params] = $this->filterClause($status, $type, $queue);

        try {
            $countSql = 'SELECT COUNT(*) FROM cms_jobs' . $where;
            $countStmt = $this->pdo->prepare($countSql);
            $countStmt->execute($params);
            $total = (int) $countStmt->fetchColumn();

            $sql = 'SELECT * FROM cms_jobs' . $where . ' ORDER BY id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset;
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $out = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                if (is_array($row)) {
                    $out[] = Job::fromRow($row);
                }
            }

            return ['items' => $out, 'total' => $total];
        } catch (PDOException) {
            return ['items' => [], 'total' => 0];
        }
    }

    /**
     * @return list<string>
     */
    public function distinctTypes(int $limit = 40): array
    {
        if (!$this->tableExists()) {
            return [];
        }

        $limit = max(1, min(100, $limit));
        try {
            $stmt = $this->pdo->query(
                'SELECT DISTINCT type FROM cms_jobs ORDER BY type ASC LIMIT ' . $limit
            );
            if ($stmt === false) {
                return [];
            }
            $out = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                if (is_array($row) && isset($row['type'])) {
                    $out[] = (string) $row['type'];
                }
            }

            return $out;
        } catch (PDOException) {
            return [];
        }
    }

    public function retryFailed(int $id): bool
    {
        $job = $this->findById($id);
        if ($job === null || $job->status !== JobStatus::FAILED) {
            return false;
        }

        $stmt = $this->pdo->prepare(
            'UPDATE cms_jobs
             SET status = :pending, attempts = 0, available_at = UTC_TIMESTAMP(),
                 reserved_at = NULL, reserved_by = NULL, completed_at = NULL,
                 last_error = CONCAT(COALESCE(last_error, ""), "\nManually requeued from admin."),
                 updated_at = UTC_TIMESTAMP()
             WHERE id = :id AND status = :failed'
        );
        $stmt->execute([
            'pending' => JobStatus::PENDING,
            'id' => $id,
            'failed' => JobStatus::FAILED,
        ]);

        return $stmt->rowCount() > 0;
    }

    public function cancelPending(int $id): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE cms_jobs
             SET status = :cancelled, updated_at = UTC_TIMESTAMP(), completed_at = UTC_TIMESTAMP()
             WHERE id = :id AND status = :pending'
        );
        $stmt->execute([
            'cancelled' => JobStatus::CANCELLED,
            'id' => $id,
            'pending' => JobStatus::PENDING,
        ]);

        return $stmt->rowCount() > 0;
    }

    public function purgeCompletedOlderThanDays(int $days): int
    {
        if (!$this->tableExists()) {
            return 0;
        }

        $days = max(1, min(365, $days));
        $stmt = $this->pdo->prepare(
            'DELETE FROM cms_jobs
             WHERE status = :completed
               AND completed_at IS NOT NULL
               AND completed_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL ' . $days . ' DAY)'
        );
        $stmt->execute(['completed' => JobStatus::COMPLETED]);

        return $stmt->rowCount();
    }

    /**
     * @return array{0: string, 1: array<string, string|int>}
     */
    private function filterClause(?string $status, ?string $type, string $queue): array
    {
        $parts = [];
        $params = [];
        if ($status !== null && $status !== '' && JobStatus::isValid($status)) {
            $parts[] = 'status = :status';
            $params['status'] = $status;
        }
        if ($type !== null && $type !== '') {
            $parts[] = 'type = :type';
            $params['type'] = mb_substr($type, 0, 128);
        }
        $queue = trim($queue);
        if ($queue !== '') {
            $parts[] = 'queue = :queue';
            $params['queue'] = mb_substr($queue, 0, 64);
        }
        $where = $parts === [] ? '' : (' WHERE ' . implode(' AND ', $parts));

        return [$where, $params];
    }

    private function scalar(string $sql): int
    {
        $v = $this->pdo->query($sql);

        return $v !== false ? (int) $v->fetchColumn() : 0;
    }
}
