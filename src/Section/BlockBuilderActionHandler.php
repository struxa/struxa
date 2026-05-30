<?php

declare(strict_types=1);

namespace App\Section;

use App\Event\Events;
use App\Event\StorefrontCachesInvalidateEvent;
use App\Flash;

/**
 * Shared POST handler for page and content-entry block builders.
 */
final class BlockBuilderActionHandler
{
    public function __construct(private readonly SectionManager $sections)
    {
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array{
     *   kind: 'redirect'|'json',
     *   url?: string,
     *   json?: array<string, mixed>,
     *   flash_error?: string,
     *   flash_success?: string
     * }
     */
    public function handle(
        array $body,
        int $subjectId,
        SectionStoreInterface $store,
        string $redirectUrl,
        bool $wantsJsonReorder,
        string $invalidatePrefix,
    ): array {
        $action = (string) ($body['_action'] ?? '');

        if ($action === 'add') {
            $key = trim((string) ($body['section_key'] ?? ''));
            if (!$this->sections->has($key)) {
                return ['kind' => 'redirect', 'url' => $redirectUrl, 'flash_error' => 'Unknown section type.'];
            }
            $store->insert(
                $subjectId,
                $store->nextSortOrder($subjectId),
                $key,
                $this->sections->defaultData($key),
                $this->sections->defaultOptions($key)
            );
            Events::dispatch(new StorefrontCachesInvalidateEvent($invalidatePrefix . '_added'));

            return ['kind' => 'redirect', 'url' => $redirectUrl, 'flash_success' => 'Section added.'];
        }

        if ($action === 'delete') {
            $sid = isset($body['section_id']) ? (int) $body['section_id'] : 0;
            if ($sid > 0 && $store->belongs($sid, $subjectId)) {
                $store->delete($sid);
                Events::dispatch(new StorefrontCachesInvalidateEvent($invalidatePrefix . '_deleted'));

                return ['kind' => 'redirect', 'url' => $redirectUrl, 'flash_success' => 'Section removed.'];
            }

            return ['kind' => 'redirect', 'url' => $redirectUrl];
        }

        if ($action === 'duplicate') {
            $sid = isset($body['section_id']) ? (int) $body['section_id'] : 0;
            $src = $sid > 0 ? $store->findById($sid) : null;
            if ($src !== null && $store->belongs($sid, $subjectId)) {
                $store->insert(
                    $subjectId,
                    $store->nextSortOrder($subjectId),
                    (string) $src->sectionKey,
                    is_array($src->data) ? $src->data : [],
                    is_array($src->options) ? $src->options : []
                );
                Events::dispatch(new StorefrontCachesInvalidateEvent($invalidatePrefix . '_duplicated'));

                return ['kind' => 'redirect', 'url' => $redirectUrl, 'flash_success' => 'Section duplicated.'];
            }

            return ['kind' => 'redirect', 'url' => $redirectUrl];
        }

        if ($action === 'move') {
            $sid = isset($body['section_id']) ? (int) $body['section_id'] : 0;
            $dir = (string) ($body['direction'] ?? '');
            $rows = $store->list($subjectId);
            $ids = array_map(static fn (object $r): int => (int) $r->id, $rows);
            $idx = array_search($sid, $ids, true);
            if ($idx !== false) {
                if ($dir === 'up' && $idx > 0) {
                    $tmp = $ids[$idx - 1];
                    $ids[$idx - 1] = $ids[$idx];
                    $ids[$idx] = $tmp;
                    $store->reorder($subjectId, $ids);
                    Events::dispatch(new StorefrontCachesInvalidateEvent($invalidatePrefix . '_reordered'));

                    return ['kind' => 'redirect', 'url' => $redirectUrl, 'flash_success' => 'Order updated.'];
                }
                if ($dir === 'down' && $idx < count($ids) - 1) {
                    $tmp = $ids[$idx + 1];
                    $ids[$idx + 1] = $ids[$idx];
                    $ids[$idx] = $tmp;
                    $store->reorder($subjectId, $ids);
                    Events::dispatch(new StorefrontCachesInvalidateEvent($invalidatePrefix . '_reordered'));

                    return ['kind' => 'redirect', 'url' => $redirectUrl, 'flash_success' => 'Order updated.'];
                }
            }

            return ['kind' => 'redirect', 'url' => $redirectUrl];
        }

        if ($action === 'reorder') {
            $order = $body['order'] ?? [];
            $order = is_array($order) ? $order : [];
            $ids = [];
            foreach ($order as $v) {
                $ids[] = (int) $v;
            }
            $ids = array_values(array_filter($ids, static fn (int $i): bool => $i > 0));
            $saved = false;
            if ($ids !== []) {
                $store->reorder($subjectId, $ids);
                $saved = true;
                Events::dispatch(new StorefrontCachesInvalidateEvent($invalidatePrefix . '_reordered'));
            }

            if ($wantsJsonReorder) {
                return ['kind' => 'json', 'json' => ['ok' => $saved]];
            }

            if ($saved) {
                return ['kind' => 'redirect', 'url' => $redirectUrl, 'flash_success' => 'Order saved.'];
            }

            return ['kind' => 'redirect', 'url' => $redirectUrl];
        }

        return ['kind' => 'redirect', 'url' => $redirectUrl];
    }

    /**
     * @param array{kind: string, url?: string, json?: array<string, mixed>, flash_error?: string, flash_success?: string} $result
     */
    public static function applyFlash(array $result): void
    {
        if (isset($result['flash_error'])) {
            Flash::set('error', $result['flash_error']);
        }
        if (isset($result['flash_success'])) {
            Flash::set('success', $result['flash_success']);
        }
    }
}
