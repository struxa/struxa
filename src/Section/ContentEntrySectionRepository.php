<?php

declare(strict_types=1);

namespace App\Section;

use PDO;

final class ContentEntrySectionRepository
{
    private const TABLE = 'cms_content_entry_sections';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return list<ContentEntrySection>
     */
    public function listForEntry(int $entryId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, content_entry_id, sort_order, section_key, data_json, options_json FROM '
            . self::TABLE . ' WHERE content_entry_id = ? ORDER BY sort_order ASC, id ASC'
        );
        $stmt->execute([$entryId]);
        $out = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $out[] = ContentEntrySection::fromRow($row);
        }

        return $out;
    }

    public function findById(int $id): ?ContentEntrySection
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, content_entry_id, sort_order, section_key, data_json, options_json FROM '
            . self::TABLE . ' WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : ContentEntrySection::fromRow($row);
    }

    public function belongsToEntry(int $sectionId, int $entryId): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM ' . self::TABLE . ' WHERE id = ? AND content_entry_id = ? LIMIT 1');
        $stmt->execute([$sectionId, $entryId]);

        return (bool) $stmt->fetchColumn();
    }

    public function countForEntry(int $entryId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM ' . self::TABLE . ' WHERE content_entry_id = ?');
        $stmt->execute([$entryId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $options
     */
    public function insert(int $entryId, int $sortOrder, string $sectionKey, array $data, array $options): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO ' . self::TABLE . ' (content_entry_id, sort_order, section_key, data_json, options_json) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $entryId,
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
     * @param list<int> $idsInOrder
     */
    public function reorderForEntry(int $entryId, array $idsInOrder): void
    {
        if ($idsInOrder === []) {
            return;
        }
        $this->pdo->beginTransaction();
        try {
            $check = $this->pdo->prepare(
                'SELECT COUNT(*) FROM ' . self::TABLE . ' WHERE content_entry_id = ? AND id IN ('
                . implode(',', array_fill(0, count($idsInOrder), '?')) . ')'
            );
            $check->execute(array_merge([$entryId], $idsInOrder));
            if ((int) $check->fetchColumn() !== count($idsInOrder)) {
                $this->pdo->rollBack();

                return;
            }
            $u = $this->pdo->prepare('UPDATE ' . self::TABLE . ' SET sort_order = ? WHERE id = ? AND content_entry_id = ?');
            foreach ($idsInOrder as $i => $sid) {
                $u->execute([$i, (int) $sid, $entryId]);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function nextSortOrder(int $entryId): int
    {
        $stmt = $this->pdo->prepare('SELECT COALESCE(MAX(sort_order), -1) + 1 FROM ' . self::TABLE . ' WHERE content_entry_id = ?');
        $stmt->execute([$entryId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @return list<array{type: string, sort_order: int, data: array<string, mixed>, options: array<string, mixed>}>
     */
    public function exportBlocksForEntry(int $entryId): array
    {
        $out = [];
        foreach ($this->listForEntry($entryId) as $row) {
            $out[] = [
                'type' => $row->sectionKey,
                'sort_order' => $row->sortOrder,
                'data' => $row->data,
                'options' => $row->options,
            ];
        }

        return $out;
    }

    public function deleteAllForEntry(int $entryId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM ' . self::TABLE . ' WHERE content_entry_id = ?');
        $stmt->execute([$entryId]);
    }

    /**
     * @param list<array{type?: string, section_key?: string, sort_order?: int, data?: array<string, mixed>, options?: array<string, mixed>}> $blocks
     */
    public function replaceAllForEntry(int $entryId, array $blocks): void
    {
        $this->pdo->beginTransaction();
        try {
            $this->deleteAllForEntry($entryId);
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
                $this->insert($entryId, $sort, $key, $data, $opts);
                ++$sort;
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
}
