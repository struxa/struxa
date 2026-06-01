<?php

declare(strict_types=1);

use App\Access\ActivityLogger;
use App\Access\PermissionSlug;
use App\Flash;
use App\Http\Middleware\RequireCmsStaff;
use App\Http\Middleware\RequirePermission;
use App\Maintenance\MaintenanceService;
use App\Jobs\JobRepository;
use App\Jobs\Jobs;
use App\Media\MediaCompressionSettings;
use App\Media\MediaLibraryOptimizer;
use App\Media\MediaRepository;
use App\Settings;
use App\Settings\SettingsRepository;
use PHPAuth\Auth;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Slim\Routing\RouteContext;
use Slim\Views\Twig;

/**
 * @param callable(): array<string, mixed> $viewData
 */
return static function (App $app, Twig $twig, Auth $auth, \PDO $pdo, callable $viewData): void {
    $middleware = new RequireCmsStaff($auth, $pdo);
    $perm = new RequirePermission($pdo, [PermissionSlug::MANAGE_SETTINGS]);
    $root = dirname(__DIR__);
    $maintenance = new MaintenanceService($pdo, $root);
    $mediaRepo = new MediaRepository($pdo);
    $optimizer = new MediaLibraryOptimizer($mediaRepo, $root);
    $activity = new ActivityLogger($pdo);
    $jobRepository = new JobRepository($pdo);

    $adminContext = static fn (): array => array_merge($viewData(), []);
    $withCmsUser = static function (Request $request, array $data): array {
        /** @var array<string, mixed> $cmsUser */
        $cmsUser = $request->getAttribute('cms_user') ?? [];

        return array_merge($data, ['cms_user' => $cmsUser]);
    };

    $cmsUserId = static function (Request $request): ?int {
        /** @var array<string, mixed> $u */
        $u = $request->getAttribute('cms_user') ?? [];
        $id = isset($u['id']) ? (int) $u['id'] : 0;

        return $id > 0 ? $id : null;
    };

    $app->group('/admin', function (\Slim\Routing\RouteCollectorProxy $group) use (
        $twig,
        $adminContext,
        $withCmsUser,
        $pdo,
        $maintenance,
        $mediaRepo,
        $optimizer,
        $activity,
        $cmsUserId,
        $jobRepository
    ): void {
        $group->get('/tools/maintenance', function (Request $request, Response $response) use (
            $twig,
            $adminContext,
            $withCmsUser,
            $maintenance,
            $mediaRepo,
            $jobRepository
        ): Response {
            $stats = $maintenance->stats();
            $library = $mediaRepo->libraryStats();

            return $twig->render($response, 'admin/tools/maintenance.twig', $withCmsUser($request, array_merge($adminContext(), [
                'admin_nav' => 'maintenance_tools',
                'maintenance_stats' => $stats,
                'media_library_stats' => $library,
                'media_compress_enabled' => MediaCompressionSettings::isEnabled(),
                'media_compress_caps' => MediaCompressionSettings::capabilities(),
                'maintenance_auto_purge' => Settings::get('maintenance_auto_purge', '0') === '1',
                'job_counts' => $jobRepository->counts(),
                'jobs_last_worker_at' => \App\Jobs\JobRunTracker::lastRunAt(),
            ])));
        })->setName('admin.tools.maintenance');

        $group->post('/tools/maintenance', function (Request $request, Response $response) use (
            $maintenance,
            $activity,
            $cmsUserId,
            $pdo
        ): Response {
            $body = $request->getParsedBody();
            $body = is_array($body) ? $body : [];
            $action = trim((string) ($body['action'] ?? ''));
            $back = RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.tools.maintenance');
            $days = isset($body['days']) && ctype_digit((string) $body['days']) ? max(1, (int) $body['days']) : 30;

            $result = match ($action) {
                'purge_preview_tokens' => $maintenance->purgePreviewTokensExpired(),
                'purge_external_links' => $maintenance->purgeExternalLinkClicks($days),
                'purge_ip_block_hits' => $maintenance->purgeIpBlockHits($days),
                'purge_ai_chat' => $maintenance->purgeAiChatMessages($days),
                'purge_not_found_logs' => $maintenance->purgeNotFoundLogs($days),
                'purge_not_found_events' => $maintenance->purgeNotFoundHitEvents($days),
                'purge_activity_logs' => $maintenance->purgeActivityLogs($days),
                'clear_media_derivatives' => $maintenance->clearMediaDerivativeCache(),
                'purge_excess_revisions' => $maintenance->purgeExcessRevisions(),
                'run_scheduled' => $maintenance->runScheduledPurges(),
                'enqueue_media_compress' => null,
                'enqueue_scheduled_purges' => null,
                'enqueue_publish_due' => null,
                'save_auto_purge' => null,
                'save_revision_retention' => null,
                default => null,
            };

            if ($action === 'save_revision_retention') {
                $settingsRepo = new SettingsRepository($pdo);
                $pageMax = isset($body['revision_retention_page_max']) && ctype_digit((string) $body['revision_retention_page_max'])
                    ? (int) $body['revision_retention_page_max'] : 50;
                $entryMax = isset($body['revision_retention_entry_max']) && ctype_digit((string) $body['revision_retention_entry_max'])
                    ? (int) $body['revision_retention_entry_max'] : 50;
                $pageMax = max(0, min(500, $pageMax));
                $entryMax = max(0, min(500, $entryMax));
                $settingsRepo->upsert('revision_retention_page_max', (string) $pageMax, true);
                $settingsRepo->upsert('revision_retention_entry_max', (string) $entryMax, true);
                Settings::reload($pdo);
                Flash::set('success', 'Revision retention limits saved.');

                return $response->withHeader('Location', $back)->withStatus(302);
            }

            if ($action === 'save_auto_purge') {
                $enabled = !empty($body['maintenance_auto_purge']);
                (new SettingsRepository($pdo))->upsert('maintenance_auto_purge', $enabled ? '1' : '0', true);
                Settings::reload($pdo);
                Flash::set('success', 'Maintenance settings saved.');

                return $response->withHeader('Location', $back)->withStatus(302);
            }

            if (in_array($action, ['enqueue_media_compress', 'enqueue_scheduled_purges', 'enqueue_publish_due'], true)) {
                $jobId = match ($action) {
                    'enqueue_media_compress' => Jobs::queue()->enqueueMediaCompressBatch(),
                    'enqueue_scheduled_purges' => Jobs::queue()->enqueueScheduledPurges(),
                    'enqueue_publish_due' => Jobs::queue()->enqueuePublishDue(),
                    default => 0,
                };
                $activity->log($cmsUserId($request), 'maintenance.enqueue_job', null, null, [
                    'action' => $action,
                    'job_id' => $jobId,
                ]);
                Flash::set('success', 'Background job #' . $jobId . ' queued. Run php bin/cms.php jobs:work on a cron timer.');

                return $response->withHeader('Location', $back)->withStatus(302);
            }

            if ($result === null) {
                Flash::set('error', 'Unknown action.');

                return $response->withHeader('Location', $back)->withStatus(302);
            }

            $total = array_sum($result);
            $activity->log($cmsUserId($request), 'maintenance.purge', null, null, [
                'action' => $action,
                'result' => $result,
            ]);
            Flash::set('success', 'Maintenance complete — ' . $total . ' row(s) or file(s) removed.');

            return $response->withHeader('Location', $back)->withStatus(302);
        })->setName('admin.tools.maintenance.process');

        $group->post('/tools/maintenance/compress-batch', function (Request $request, Response $response) use (
            $optimizer,
            $activity,
            $cmsUserId
        ): Response {
            $body = $request->getParsedBody();
            $body = is_array($body) ? $body : [];
            $afterId = isset($body['after_id']) && ctype_digit((string) $body['after_id']) ? (int) $body['after_id'] : 0;
            $limit = isset($body['limit']) && ctype_digit((string) $body['limit']) ? (int) $body['limit'] : 20;

            $result = $optimizer->compressBatch($afterId, $limit);
            if (($result['ok'] ?? false) !== true) {
                $response->getBody()->write(json_encode([
                    'ok' => false,
                    'error' => (string) ($result['error'] ?? 'Optimization failed.'),
                ], JSON_THROW_ON_ERROR));

                return $response->withHeader('Content-Type', 'application/json; charset=utf-8')->withStatus(400);
            }

            if (($result['optimized'] ?? 0) > 0) {
                $activity->log($cmsUserId($request), 'maintenance.media_batch_compress', null, null, [
                    'processed' => $result['processed'],
                    'optimized' => $result['optimized'],
                    'bytes_saved' => $result['bytes_saved'],
                ]);
            }

            $response->getBody()->write(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

            return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
        })->setName('admin.tools.maintenance.compress_batch');
    })->add($perm)->add($middleware);
};
