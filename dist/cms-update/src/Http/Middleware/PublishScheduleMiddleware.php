<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Cache\FileCache;
use App\Preview\PreviewTokenRepository;
use App\Publishing\PublishScheduleService;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Runs scheduled publish/unpublish occasionally (debounced) so crons are optional.
 */
final class PublishScheduleMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly FileCache $internalCache,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($this->internalCache->get('publish_schedule_tick') === null) {
            $this->internalCache->set('publish_schedule_tick', 1, 45);
            try {
                (new PreviewTokenRepository($this->pdo))->deleteExpired();
                (new PublishScheduleService($this->pdo))->runDue();
            } catch (\Throwable) {
                // Avoid breaking requests; failures are visible via CLI schedule:run
            }
        }

        return $handler->handle($request);
    }
}
