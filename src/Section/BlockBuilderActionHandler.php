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
    public function __construct(
        private readonly SectionManager $sections,
        private readonly ?SectionPatternRepository $patterns = null,
    ) {
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
        string $builderHost = BlockBuilderHost::PAGE,
        ?int $createdBy = null,
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

        if ($action === 'add_from_pattern') {
            return $this->addFromPattern($body, $subjectId, $store, $redirectUrl, $invalidatePrefix, $builderHost);
        }

        if ($action === 'save_as_pattern') {
            return $this->saveAsPattern($body, $subjectId, $store, $redirectUrl, $builderHost, $createdBy);
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
     * @param array<string, mixed> $body
     *
     * @return array{kind: 'redirect', url: string, flash_error?: string, flash_success?: string}
     */
    private function addFromPattern(
        array $body,
        int $subjectId,
        SectionStoreInterface $store,
        string $redirectUrl,
        string $invalidatePrefix,
        string $builderHost,
    ): array {
        if ($this->patterns === null || !$this->patterns->tableExists()) {
            return ['kind' => 'redirect', 'url' => $redirectUrl, 'flash_error' => 'Section patterns are unavailable.'];
        }

        $patternId = isset($body['pattern_id']) ? (int) $body['pattern_id'] : 0;
        $pattern = $patternId > 0 ? $this->patterns->findById($patternId) : null;
        if ($pattern === null || !$pattern->supportsHost($builderHost)) {
            return ['kind' => 'redirect', 'url' => $redirectUrl, 'flash_error' => 'Pattern not found.'];
        }
        if (!$this->sections->has($pattern->sectionKey)) {
            return ['kind' => 'redirect', 'url' => $redirectUrl, 'flash_error' => 'Pattern block type is not available on this site.'];
        }

        $store->insert(
            $subjectId,
            $store->nextSortOrder($subjectId),
            $pattern->sectionKey,
            $pattern->data,
            $pattern->options,
        );
        Events::dispatch(new StorefrontCachesInvalidateEvent($invalidatePrefix . '_pattern_added'));

        return ['kind' => 'redirect', 'url' => $redirectUrl, 'flash_success' => 'Pattern “' . $pattern->name . '” inserted.'];
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array{kind: 'redirect', url: string, flash_error?: string, flash_success?: string}
     */
    private function saveAsPattern(
        array $body,
        int $subjectId,
        SectionStoreInterface $store,
        string $redirectUrl,
        string $builderHost,
        ?int $createdBy,
    ): array {
        if ($this->patterns === null || !$this->patterns->tableExists()) {
            return ['kind' => 'redirect', 'url' => $redirectUrl, 'flash_error' => 'Section patterns are unavailable. Run migrations.'];
        }

        $name = trim((string) ($body['pattern_name'] ?? ''));
        if ($name === '') {
            return ['kind' => 'redirect', 'url' => $redirectUrl, 'flash_error' => 'Pattern name is required.'];
        }

        $sid = isset($body['section_id']) ? (int) $body['section_id'] : 0;
        $src = $sid > 0 ? $store->findById($sid) : null;
        if ($src === null || !$store->belongs($sid, $subjectId)) {
            return ['kind' => 'redirect', 'url' => $redirectUrl, 'flash_error' => 'Section not found.'];
        }

        $host = trim((string) ($body['pattern_host'] ?? $builderHost));
        if (!SectionPatternHost::isValid($host)) {
            $host = $builderHost;
        }

        $description = trim((string) ($body['pattern_description'] ?? ''));
        $slug = SectionPatternSlugger::ensureUnique(
            $this->patterns,
            SectionPatternSlugger::slugify($name),
        );

        $this->patterns->insert(
            $name,
            $slug,
            $description !== '' ? $description : null,
            $host,
            (string) $src->sectionKey,
            is_array($src->data) ? $src->data : [],
            is_array($src->options) ? $src->options : [],
            $createdBy,
        );

        return ['kind' => 'redirect', 'url' => $redirectUrl, 'flash_success' => 'Pattern “' . $name . '” saved.'];
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
