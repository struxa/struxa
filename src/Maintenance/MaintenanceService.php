<?php

declare(strict_types=1);

namespace App\Maintenance;

use App\Ai\AiChatMessageRepository;
use App\Analytics\ExternalLinkClickRepository;
use App\Analytics\ExternalLinkTrackingConfig;
use App\Preview\PreviewTokenRepository;
use App\Security\IpBlockHitBucketRepository;
use App\Settings;
use PDO;
use PDOException;

/**
 * Native housekeeping: log purges, derivative cache, and scheduled retention.
 */
final class MaintenanceService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $projectRoot,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function stats(): array
    {
        $mediaRs = $this->projectRoot . '/storage/cache/media-rs';
        $derivativeBytes = self::directoryBytes($mediaRs);

        return [
            'preview_tokens_total' => $this->scalarCount('SELECT COUNT(*) FROM cms_preview_tokens'),
            'preview_tokens_expired' => $this->scalarCount('SELECT COUNT(*) FROM cms_preview_tokens WHERE expires_at < NOW()'),
            'external_link_clicks' => $this->scalarCount('SELECT COUNT(*) FROM cms_external_link_clicks'),
            'ip_block_hit_buckets' => $this->scalarCount('SELECT COUNT(*) FROM cms_ip_block_hit_buckets'),
            'ai_chat_messages' => $this->scalarCount('SELECT COUNT(*) FROM cms_ai_chat_messages'),
            'not_found_logs' => $this->scalarCount('SELECT COUNT(*) FROM cms_not_found_logs'),
            'not_found_hit_events' => $this->scalarCount('SELECT COUNT(*) FROM cms_not_found_hit_events'),
            'activity_logs' => $this->scalarCount('SELECT COUNT(*) FROM cms_activity_logs'),
            'media_derivative_cache_bytes' => $derivativeBytes,
            'external_link_retention_days' => ExternalLinkTrackingConfig::retentionDays(),
            'ai_chat_retention_days' => max(0, (int) (Settings::get('ai_chat_retention_days', '30') ?? '30')),
        ];
    }

    /**
     * @return array<string, int>
     */
    public function purgePreviewTokensExpired(): array
    {
        $deleted = (new PreviewTokenRepository($this->pdo))->deleteExpired();

        return ['preview_tokens' => $deleted];
    }

    /**
     * @return array<string, int>
     */
    public function purgeExternalLinkClicks(int $daysOld): array
    {
        $deleted = (new ExternalLinkClickRepository($this->pdo))->purgeOlderThan(max(1, $daysOld));

        return ['external_link_clicks' => $deleted];
    }

    /**
     * @return array<string, int>
     */
    public function purgeIpBlockHits(int $daysOld): array
    {
        $deleted = (new IpBlockHitBucketRepository($this->pdo))->deleteOlderThanDays(max(1, $daysOld));

        return ['ip_block_hit_buckets' => $deleted];
    }

    /**
     * @return array<string, int>
     */
    public function purgeAiChatMessages(int $daysOld): array
    {
        $deleted = (new AiChatMessageRepository($this->pdo))->purgeOlderThanDays(max(1, $daysOld));

        return ['ai_chat_messages' => $deleted];
    }

    /**
     * @return array<string, int>
     */
    public function purgeNotFoundLogs(int $daysOld): array
    {
        $days = max(1, $daysOld);
        $stmt = $this->pdo->prepare(
            'DELETE FROM cms_not_found_logs WHERE last_seen_at < DATE_SUB(NOW(), INTERVAL ' . $days . ' DAY)'
        );
        $stmt->execute();

        return ['not_found_logs' => $stmt->rowCount()];
    }

    /**
     * @return array<string, int>
     */
    public function purgeNotFoundHitEvents(int $daysOld): array
    {
        $days = max(1, $daysOld);
        try {
            $stmt = $this->pdo->prepare(
                'DELETE FROM cms_not_found_hit_events WHERE seen_at < DATE_SUB(NOW(), INTERVAL ' . $days . ' DAY)'
            );
            $stmt->execute();

            return ['not_found_hit_events' => $stmt->rowCount()];
        } catch (PDOException) {
            return ['not_found_hit_events' => 0];
        }
    }

    /**
     * @return array<string, int>
     */
    public function purgeActivityLogs(int $daysOld): array
    {
        $days = max(1, $daysOld);
        $stmt = $this->pdo->prepare(
            'DELETE FROM cms_activity_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL ' . $days . ' DAY)'
        );
        $stmt->execute();

        return ['activity_logs' => $stmt->rowCount()];
    }

    /**
     * @return array<string, int>
     */
    public function clearMediaDerivativeCache(): array
    {
        $dir = $this->projectRoot . '/storage/cache/media-rs';
        $removed = 0;
        if (is_dir($dir)) {
            foreach (glob($dir . '/*') ?: [] as $f) {
                if (is_file($f) && @unlink($f)) {
                    ++$removed;
                }
            }
        }

        return ['media_derivative_files' => $removed];
    }

    /**
     * Safe automated purges for cron / schedule:run (respects configured retention).
     *
     * @return array<string, int>
     */
    public function runScheduledPurges(): array
    {
        $out = $this->purgePreviewTokensExpired();

        $linkDays = ExternalLinkTrackingConfig::retentionDays();
        if ($linkDays > 0) {
            $out = array_merge($out, $this->purgeExternalLinkClicks($linkDays));
        }

        $aiDays = max(0, (int) (Settings::get('ai_chat_retention_days', '30') ?? '30'));
        if ($aiDays > 0) {
            $out = array_merge($out, $this->purgeAiChatMessages($aiDays));
        }

        return $out;
    }

    public static function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024, 1) . ' KB';
        }
        if ($bytes < 1024 * 1024 * 1024) {
            return round($bytes / (1024 * 1024), 1) . ' MB';
        }

        return round($bytes / (1024 * 1024 * 1024), 2) . ' GB';
    }

    private function scalarCount(string $sql): int
    {
        try {
            $v = $this->pdo->query($sql);

            return $v !== false ? (int) $v->fetchColumn() : 0;
        } catch (PDOException) {
            return 0;
        }
    }

    private static function directoryBytes(string $dir): int
    {
        if (!is_dir($dir)) {
            return 0;
        }
        $bytes = 0;
        foreach (glob($dir . '/*') ?: [] as $f) {
            if (is_file($f)) {
                $bytes += (int) filesize($f);
            }
        }

        return $bytes;
    }
}
