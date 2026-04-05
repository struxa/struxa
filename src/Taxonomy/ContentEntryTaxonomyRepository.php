<?php

declare(strict_types=1);

namespace App\Taxonomy;

use PDO;

final class ContentEntryTaxonomyRepository
{
    private const TABLE = 'cms_content_entry_taxonomy_terms';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array<int, list<int>> taxonomy_id => term ids
     */
    public function termIdsByTaxonomyForEntry(int $entryId): array
    {
        $sql = 'SELECT tt.taxonomy_id, e.taxonomy_term_id FROM ' . self::TABLE . ' e
                INNER JOIN cms_taxonomy_terms tt ON tt.id = e.taxonomy_term_id
                WHERE e.content_entry_id = ?';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$entryId]);
        $out = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $tx = (int) $row['taxonomy_id'];
            $out[$tx] ??= [];
            $out[$tx][] = (int) $row['taxonomy_term_id'];
        }

        return $out;
    }

    /**
     * @return list<int> term ids
     */
    public function termIdsForEntry(int $entryId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT taxonomy_term_id FROM ' . self::TABLE . ' WHERE content_entry_id = ?'
        );
        $stmt->execute([$entryId]);
        $out = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $out[] = (int) $row['taxonomy_term_id'];
        }

        return $out;
    }

    /**
     * @param list<int> $termIds
     */
    public function replaceForEntry(int $entryId, array $termIds): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM ' . self::TABLE . ' WHERE content_entry_id = ?');
        $stmt->execute([$entryId]);
        $seen = [];
        $ins = $this->pdo->prepare(
            'INSERT INTO ' . self::TABLE . ' (content_entry_id, taxonomy_term_id) VALUES (?, ?)'
        );
        foreach ($termIds as $tid) {
            $tid = (int) $tid;
            if ($tid < 1 || isset($seen[$tid])) {
                continue;
            }
            $seen[$tid] = true;
            $ins->execute([$entryId, $tid]);
        }
    }

    /**
     * @return list<array{taxonomy: Taxonomy, terms: list<TaxonomyTerm>}>
     */
    public function termsGroupedForEntry(int $entryId): array
    {
        $sql = 'SELECT
                tt.id, tt.taxonomy_id, tt.name, tt.slug, tt.description, tt.parent_id, tt.sort_order, tt.created_at, tt.updated_at,
                tx.id AS tid, tx.content_type_id, tx.name AS tname, tx.slug AS tslug, tx.description AS tdesc,
                tx.taxonomy_type, tx.is_hierarchical, tx.created_at AS tc_at, tx.updated_at AS tu_at
                FROM ' . self::TABLE . ' e
                INNER JOIN cms_taxonomy_terms tt ON tt.id = e.taxonomy_term_id
                INNER JOIN cms_taxonomies tx ON tx.id = tt.taxonomy_id
                WHERE e.content_entry_id = ?
                ORDER BY tx.name ASC, tt.sort_order ASC, tt.name ASC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$entryId]);
        $groups = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $tid = (int) $row['tid'];
            if (!isset($groups[$tid])) {
                $groups[$tid] = [
                    'taxonomy' => new Taxonomy(
                        $tid,
                        (int) $row['content_type_id'],
                        (string) $row['tname'],
                        (string) $row['tslug'],
                        isset($row['tdesc']) && $row['tdesc'] !== '' ? (string) $row['tdesc'] : null,
                        (string) $row['taxonomy_type'],
                        (bool) ((int) $row['is_hierarchical']),
                        (string) $row['tc_at'],
                        (string) $row['tu_at']
                    ),
                    'terms' => [],
                ];
            }
            $groups[$tid]['terms'][] = TaxonomyTerm::fromRow([
                'id' => $row['id'],
                'taxonomy_id' => $row['taxonomy_id'],
                'name' => $row['name'],
                'slug' => $row['slug'],
                'description' => $row['description'],
                'parent_id' => $row['parent_id'],
                'sort_order' => $row['sort_order'],
                'created_at' => $row['created_at'],
                'updated_at' => $row['updated_at'],
            ]);
        }

        return array_values($groups);
    }
}
