<?php

declare(strict_types=1);

namespace App\Jobs;

/**
 * View-model rows for admin job queue templates.
 */
final class JobAdminPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function row(Job $job): array
    {
        $summary = $job->resultSummary;
        if ($summary === null || $summary === '') {
            $summary = $job->lastError;
        }
        if ($summary !== null && mb_strlen($summary) > 120) {
            $summary = mb_substr($summary, 0, 117) . '…';
        }

        return [
            'id' => $job->id,
            'queue' => $job->queue,
            'type' => $job->type,
            'type_label' => JobTypeLabels::label($job->type),
            'status' => $job->status,
            'status_label' => ucfirst($job->status),
            'attempts' => $job->attempts,
            'max_attempts' => $job->maxAttempts,
            'available_at' => $job->availableAt,
            'reserved_at' => $job->reservedAt,
            'reserved_by' => $job->reservedBy,
            'created_at' => $job->createdAt,
            'completed_at' => $job->completedAt,
            'dedupe_key' => $job->dedupeKey,
            'summary' => $summary ?? '—',
            'is_failed' => $job->status === JobStatus::FAILED,
            'is_pending' => $job->status === JobStatus::PENDING,
            'is_running' => $job->status === JobStatus::RUNNING,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function detail(Job $job): array
    {
        $row = self::row($job);
        $payloadJson = json_encode($job->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $row['payload'] = $job->payload;
        $row['payload_json'] = is_string($payloadJson) ? $payloadJson : '{}';
        $row['result_summary'] = $job->resultSummary;
        $row['last_error'] = $job->lastError;
        $row['can_retry'] = $job->status === JobStatus::FAILED;
        $row['can_cancel'] = $job->status === JobStatus::PENDING;

        return $row;
    }

    /**
     * @param list<Job> $jobs
     * @return list<array<string, mixed>>
     */
    public static function rows(array $jobs): array
    {
        $out = [];
        foreach ($jobs as $job) {
            $out[] = self::row($job);
        }

        return $out;
    }
}
