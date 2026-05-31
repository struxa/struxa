<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Content\ContentEntryRepository;
use App\Event\Events;
use App\Event\StorefrontCachesInvalidateEvent;
use App\Maintenance\MaintenanceService;
use App\Media\MediaLibraryOptimizer;
use App\Media\MediaRepository;
use App\Page\PageRepository;
use App\Preview\PreviewTokenRepository;
use App\Publishing\PublishScheduleService;
use App\Publishing\ScheduleRunTracker;
use App\Seo\SitemapOptions;
use App\Seo\SitemapService;
use App\Settings;
use App\Settings\SiteUrlResolver;

final class BuiltinJobHandlers
{
    public static function register(JobHandlerRegistry $registry, JobHandlerContext $context): void
    {
        $root = $context->projectRoot;
        $pdo = $context->pdo;
        $queue = $context->queue;

        $registry->register(JobType::MAINTENANCE_PURGE_SCHEDULED, static function (Job $job, JobHandlerContext $ctx) use ($root): array {
            unset($job);
            $maint = new MaintenanceService($ctx->pdo, $root);
            $result = $maint->runScheduledPurges();
            $total = array_sum($result);

            return [
                'ok' => true,
                'message' => $total > 0 ? "{$total} row(s) purged." : 'Nothing to purge.',
            ];
        });

        $registry->register(JobType::SCHEDULE_PUBLISH_DUE, static function (Job $job, JobHandlerContext $ctx) use ($root): array {
            unset($job);
            if (Settings::get('maintenance_auto_purge', '0') !== '1') {
                (new PreviewTokenRepository($ctx->pdo))->deleteExpired();
            }
            $report = (new PublishScheduleService($ctx->pdo))->runDue();
            ScheduleRunTracker::record($ctx->pdo);
            $errors = $report['errors'];
            if ($errors !== []) {
                return [
                    'ok' => false,
                    'message' => implode('; ', $errors),
                    'retry' => true,
                ];
            }

            return [
                'ok' => true,
                'message' => sprintf(
                    'Entries +%d/-%d, pages +%d/-%d.',
                    $report['published_entries'],
                    $report['unpublished_entries'],
                    $report['published_pages'],
                    $report['unpublished_pages'],
                ),
            ];
        });

        $registry->register(JobType::MEDIA_COMPRESS_BATCH, static function (Job $job, JobHandlerContext $ctx) use ($root): array {
            $afterId = max(0, (int) ($job->payload['after_id'] ?? 0));
            $limit = max(1, min(50, (int) ($job->payload['limit'] ?? 20)));
            $chain = !empty($job->payload['chain']);

            $optimizer = new MediaLibraryOptimizer(new MediaRepository($ctx->pdo), $root);
            $result = $optimizer->compressBatch($afterId, $limit);
            if (($result['ok'] ?? false) !== true) {
                return [
                    'ok' => false,
                    'message' => (string) ($result['error'] ?? 'Compression failed.'),
                    'retry' => false,
                ];
            }

            $chainJobs = [];
            if ($chain && ($result['done'] ?? true) !== true) {
                $chainJobs[] = [
                    'type' => JobType::MEDIA_COMPRESS_BATCH,
                    'payload' => [
                        'after_id' => (int) ($result['next_after_id'] ?? $afterId),
                        'limit' => $limit,
                        'chain' => true,
                    ],
                ];
            }

            return [
                'ok' => true,
                'message' => sprintf(
                    'Processed %d, optimized %d, saved %d bytes.',
                    (int) ($result['processed'] ?? 0),
                    (int) ($result['optimized'] ?? 0),
                    (int) ($result['bytes_saved'] ?? 0),
                ),
                'chain' => $chainJobs,
            ];
        });

        $registry->register(JobType::SITEMAP_WARM, static function (Job $job, JobHandlerContext $ctx): array {
            unset($job);
            $siteUrl = SiteUrlResolver::resolve();
            $service = new SitemapService(
                $ctx->pdo,
                new PageRepository($ctx->pdo),
                new ContentEntryRepository($ctx->pdo),
            );
            if (!SitemapOptions::sitemapPubliclyEnabled()) {
                return [
                    'ok' => true,
                    'message' => 'Sitemap disabled — skipped warm.',
                ];
            }

            $urls = $service->collectUrls($siteUrl);
            Events::dispatch(new StorefrontCachesInvalidateEvent('sitemap_warm'));

            return [
                'ok' => true,
                'message' => count($urls) . ' URL(s) warmed.',
            ];
        });

    }
}
