<?php

declare(strict_types=1);

namespace App\Section;

use PDO;

final class PageSectionRepository
{
    private const TABLE = 'cms_page_sections';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return list<PageSection>
     */
    public function listForPage(int $pageId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, page_id, sort_order, section_key, data_json, options_json FROM '
            . self::TABLE . ' WHERE page_id = ? ORDER BY sort_order ASC, id ASC'
        );
        $stmt->execute([$pageId]);
        $out = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $out[] = PageSection::fromRow($row);
        }

        return $out;
    }

    public function findById(int $id): ?PageSection
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, page_id, sort_order, section_key, data_json, options_json FROM '
            . self::TABLE . ' WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : PageSection::fromRow($row);
    }

    public function belongsToPage(int $sectionId, int $pageId): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM ' . self::TABLE . ' WHERE id = ? AND page_id = ? LIMIT 1');
        $stmt->execute([$sectionId, $pageId]);

        return (bool) $stmt->fetchColumn();
    }

    public function countForPage(int $pageId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM ' . self::TABLE . ' WHERE page_id = ?');
        $stmt->execute([$pageId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $options
     */
    public function insert(int $pageId, int $sortOrder, string $sectionKey, array $data, array $options): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO ' . self::TABLE . ' (page_id, sort_order, section_key, data_json, options_json) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $pageId,
            $sortOrder,
            $sectionKey,
            json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            $options === [] ? null : json_encode($options, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $options
     */
    public function update(int $id, int $sortOrder, string $sectionKey, array $data, array $options): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE ' . self::TABLE . ' SET sort_order = ?, section_key = ?, data_json = ?, options_json = ? WHERE id = ?'
        );
        $stmt->execute([
            $sortOrder,
            $sectionKey,
            json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            $options === [] ? null : json_encode($options, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            $id,
        ]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM ' . self::TABLE . ' WHERE id = ?');
        $stmt->execute([$id]);
    }

    /**
     * Reassign sort_order 0..n-1 for rows in the given order (must all belong to page).
     *
     * @param list<int> $idsInOrder
     */
    public function reorderForPage(int $pageId, array $idsInOrder): void
    {
        if ($idsInOrder === []) {
            return;
        }
        $this->pdo->beginTransaction();
        try {
            $check = $this->pdo->prepare('SELECT COUNT(*) FROM ' . self::TABLE . ' WHERE page_id = ? AND id IN (' . implode(',', array_fill(0, count($idsInOrder), '?')) . ')');
            $check->execute(array_merge([$pageId], $idsInOrder));
            if ((int) $check->fetchColumn() !== count($idsInOrder)) {
                $this->pdo->rollBack();

                return;
            }
            $u = $this->pdo->prepare('UPDATE ' . self::TABLE . ' SET sort_order = ? WHERE id = ? AND page_id = ?');
            foreach ($idsInOrder as $i => $sid) {
                $u->execute([$i, (int) $sid, $pageId]);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function nextSortOrder(int $pageId): int
    {
        $stmt = $this->pdo->prepare('SELECT COALESCE(MAX(sort_order), -1) + 1 FROM ' . self::TABLE . ' WHERE page_id = ?');
        $stmt->execute([$pageId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Blueprint export shape per row.
     *
     * @return list<array{type: string, sort_order: int, data: array<string, mixed>, options: array<string, mixed>}>
     */
    public function exportBlocksForPage(int $pageId): array
    {
        $out = [];
        foreach ($this->listForPage($pageId) as $row) {
            $out[] = [
                'type' => $row->sectionKey,
                'sort_order' => $row->sortOrder,
                'data' => $row->data,
                'options' => $row->options,
            ];
        }

        return $out;
    }

    public function deleteAllForPage(int $pageId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM ' . self::TABLE . ' WHERE page_id = ?');
        $stmt->execute([$pageId]);
    }

    /**
     * Replace all blocks on a page with validated export rows.
     *
     * @param list<array{type?: string, section_key?: string, sort_order?: int, data?: array<string, mixed>, options?: array<string, mixed>}> $blocks
     */
    public function replaceAllForPage(int $pageId, array $blocks): void
    {
        $this->pdo->beginTransaction();
        try {
            $this->deleteAllForPage($pageId);
            $sort = 0;
            foreach ($blocks as $block) {
                if (!is_array($block)) {
                    continue;
                }
                $key = trim((string) ($block['type'] ?? $block['section_key'] ?? ''));
                if ($key === '') {
                    continue;
                }
                $data = isset($block['data']) && is_array($block['data']) ? $block['data'] : [];
                $opts = isset($block['options']) && is_array($block['options']) ? $block['options'] : [];
                $this->insert($pageId, $sort, $key, $data, $opts);
                ++$sort;
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
}
