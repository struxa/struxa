<?php

declare(strict_types=1);

use App\Access\ActivityLogger;
use App\Access\PermissionSlug;
use App\Flash;
use App\Http\Middleware\RequireCmsStaff;
use App\Http\Middleware\RequirePermission;
use App\Jobs\CronVisibility;
use App\Jobs\JobAdminPresenter;
use App\Jobs\JobRepository;
use App\Jobs\JobStatus;
use App\Jobs\Jobs;
use PHPAuth\Auth;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Slim\Exception\HttpNotFoundException;
use Slim\Routing\RouteContext;
use Slim\Views\Twig;

/**
 * @param callable(): array<string, mixed> $viewData
 */
return static function (App $app, Twig $twig, Auth $auth, \PDO $pdo, callable $viewData): void {
    $middleware = new RequireCmsStaff($auth, $pdo);
    $perm = new RequirePermission($pdo, [PermissionSlug::MANAGE_SETTINGS]);
    $jobRepository = new JobRepository($pdo);
    $cron = new CronVisibility($pdo, $jobRepository);
    $activity = new ActivityLogger($pdo);

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
        $jobRepository,
        $cron,
        $activity,
        $cmsUserId
    ): void {
        $group->get('/tools/jobs', function (Request $request, Response $response) use (
            $twig,
            $adminContext,
            $withCmsUser,
            $jobRepository,
            $cron
        ): Response {
            $q = $request->getQueryParams();
            $status = isset($q['status']) ? trim((string) $q['status']) : '';
            if ($status !== '' && !JobStatus::isValid($status)) {
                $status = '';
            }
            $type = isset($q['type']) ? trim((string) $q['type']) : '';
            $queue = isset($q['queue']) ? trim((string) $q['queue']) : '';
            $page = isset($q['page']) && ctype_digit((string) $q['page']) ? max(1, (int) $q['page']) : 1;
            $perPage = 30;

            $result = $jobRepository->listFiltered(
                $status !== '' ? $status : null,
                $type !== '' ? $type : null,
                $queue,
                $page,
                $perPage,
            );
            $total = $result['total'];
            $totalPages = max(1, (int) ceil($total / $perPage));
            $cronSnap = $cron->snapshot();

            return $twig->render($response, 'admin/tools/jobs/index.twig', $withCmsUser($request, array_merge($adminContext(), [
                'admin_nav' => 'maintenance_tools',
                'tools_nav_active' => 'jobs',
                'cron' => $cronSnap,
                'cron_age' => [
                    'schedule' => CronVisibility::ageDescription($cronSnap['schedule_last_run_at'] ?? null),
                    'worker' => CronVisibility::ageDescription($cronSnap['jobs_last_worker_at'] ?? null),
                ],
                'job_rows' => JobAdminPresenter::rows($result['items']),
                'job_types' => $jobRepository->distinctTypes(),
                'filter_status' => $status,
                'filter_type' => $type,
                'filter_queue' => $queue,
                'page' => $page,
                'total_pages' => $totalPages,
                'total_jobs' => $total,
                'table_missing' => !$jobRepository->tableExists(),
            ])));
        })->setName('admin.tools.jobs');

        $group->get('/tools/jobs/{id:[0-9]+}', function (Request $request, Response $response, array $args) use (
            $twig,
            $adminContext,
            $withCmsUser,
            $jobRepository,
            $cron
        ): Response {
            $id = (int) $args['id'];
            $job = $jobRepository->findById($id);
            if ($job === null) {
                throw new HttpNotFoundException($request);
            }

            return $twig->render($response, 'admin/tools/jobs/show.twig', $withCmsUser($request, array_merge($adminContext(), [
                'admin_nav' => 'maintenance_tools',
                'tools_nav_active' => 'jobs',
                'cron' => $cron->snapshot(),
                'job' => JobAdminPresenter::detail($job),
            ])));
        })->setName('admin.tools.jobs.show');

        $group->post('/tools/jobs/process', function (Request $request, Response $response) use (
            $jobRepository,
            $activity,
            $cmsUserId
        ): Response {
            $body = $request->getParsedBody();
            $body = is_array($body) ? $body : [];
            $action = trim((string) ($body['action'] ?? ''));
            $jobId = isset($body['job_id']) && ctype_digit((string) $body['job_id']) ? (int) $body['job_id'] : 0;
            $parser = RouteContext::fromRequest($request)->getRouteParser();
            $back = trim((string) ($body['return_to'] ?? ''));
            if ($back === '' || !str_starts_with($back, '/admin/')) {
                $back = $parser->urlFor('admin.tools.jobs');
            }

            if (!$jobRepository->tableExists()) {
                Flash::set('error', 'Job queue table is missing. Run php bin/cms.php migrate.');

                return $response->withHeader('Location', $back)->withStatus(302);
            }

            $ok = false;
            $message = 'Unknown action.';

            switch ($action) {
                case 'retry':
                    if ($jobId < 1) {
                        $message = 'Invalid job.';
                        break;
                    }
                    $ok = $jobRepository->retryFailed($jobId);
                    $message = $ok ? 'Job #' . $jobId . ' requeued.' : 'Only failed jobs can be retried.';
                    break;
                case 'cancel':
                    if ($jobId < 1) {
                        $message = 'Invalid job.';
                        break;
                    }
                    $ok = $jobRepository->cancelPending($jobId);
                    $message = $ok ? 'Job #' . $jobId . ' cancelled.' : 'Only pending jobs can be cancelled.';
                    break;
                case 'release_stale':
                    $n = $jobRepository->releaseStale();
                    $ok = true;
                    $message = $n > 0 ? "Recovered {$n} stale running job(s)." : 'No stale running jobs found.';
                    break;
                case 'purge_completed':
                    $days = isset($body['days']) && ctype_digit((string) $body['days']) ? (int) $body['days'] : 30;
                    $n = $jobRepository->purgeCompletedOlderThanDays($days);
                    $ok = true;
                    $message = $n > 0 ? "Removed {$n} completed job(s) older than {$days} days." : 'No old completed jobs to remove.';
                    break;
                case 'enqueue_publish_due':
                    $id = Jobs::queue()->enqueuePublishDue();
                    $ok = true;
                    $message = 'Queued scheduled publish job #' . $id . '.';
                    break;
                case 'enqueue_scheduled_purges':
                    $id = Jobs::queue()->enqueueScheduledPurges();
                    $ok = true;
                    $message = 'Queued retention purge job #' . $id . '.';
                    break;
                case 'enqueue_media_compress':
                    $id = Jobs::queue()->enqueueMediaCompressBatch();
                    $ok = true;
                    $message = 'Queued media optimization job #' . $id . '.';
                    break;
                case 'enqueue_sitemap_warm':
                    $id = Jobs::queue()->enqueueSitemapWarm();
                    $ok = true;
                    $message = 'Queued sitemap warm job #' . $id . '.';
                    break;
                default:
                    Flash::set('error', $message);

                    return $response->withHeader('Location', $back)->withStatus(302);
            }

            $activity->log($cmsUserId($request), 'jobs.admin_action', null, $jobId > 0 ? $jobId : null, [
                'action' => $action,
                'ok' => $ok,
            ]);
            Flash::set($ok ? 'success' : 'error', $message);

            if ($jobId > 0 && in_array($action, ['retry', 'cancel'], true) && $ok) {
                $back = $parser->urlFor('admin.tools.jobs.show', ['id' => (string) $jobId]);
            }

            return $response->withHeader('Location', $back)->withStatus(302);
        })->setName('admin.tools.jobs.process');
    })->add($perm)->add($middleware);
};
