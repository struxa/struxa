<?php

declare(strict_types=1);

namespace App\Section;

use PDO;

final class SectionPatternRepository
{
    private const TABLE = 'cms_section_patterns';

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function tableExists(): bool
    {
        try {
            $check = $this->pdo->query("SHOW TABLES LIKE '" . self::TABLE . "'");

            return $check !== false && $check->rowCount() > 0;
        } catch (\PDOException) {
            return false;
        }
    }

    /**
     * @return list<SectionPattern>
     */
    public function listForHost(string $builderHost): array
    {
        if (!$this->tableExists()) {
            return [];
        }

        $stmt = $this->pdo->prepare(
            'SELECT * FROM ' . self::TABLE . '
             WHERE host = :both OR host = :host
             ORDER BY name ASC, id ASC'
        );
        $stmt->execute([
            'both' => SectionPatternHost::BOTH,
            'host' => $builderHost,
        ]);
        $out = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (is_array($row)) {
                $out[] = SectionPattern::fromRow($row);
            }
        }

        return $out;
    }

    /**
     * @return list<SectionPattern>
     */
    public function listAll(): array
    {
        if (!$this->tableExists()) {
            return [];
        }

        $stmt = $this->pdo->query('SELECT * FROM ' . self::TABLE . ' ORDER BY name ASC, id ASC');
        if ($stmt === false) {
            return [];
        }

        $out = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (is_array($row)) {
                $out[] = SectionPattern::fromRow($row);
            }
        }

        return $out;
    }

    public function findById(int $id): ?SectionPattern
    {
        if (!$this->tableExists()) {
            return null;
        }

        $stmt = $this->pdo->prepare('SELECT * FROM ' . self::TABLE . ' WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? SectionPattern::fromRow($row) : null;
    }

    public function slugExists(string $slug, ?int $exceptId = null): bool
    {
        if (!$this->tableExists()) {
            return false;
        }

        if ($exceptId === null) {
            $stmt = $this->pdo->prepare('SELECT 1 FROM ' . self::TABLE . ' WHERE slug = ? LIMIT 1');
            $stmt->execute([$slug]);
        } else {
            $stmt = $this->pdo->prepare('SELECT 1 FROM ' . self::TABLE . ' WHERE slug = ? AND id <> ? LIMIT 1');
            $stmt->execute([$slug, $exceptId]);
        }

        return (bool) $stmt->fetchColumn();
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $options
     */
    public function insert(
        string $name,
        string $slug,
        ?string $description,
        string $host,
        string $sectionKey,
        array $data,
        array $options,
        ?int $createdBy,
    ): int {
        $host = SectionPatternHost::isValid($host) ? $host : SectionPatternHost::BOTH;
        $stmt = $this->pdo->prepare(
            'INSERT INTO ' . self::TABLE . ' (name, slug, description, host, section_key, data_json, options_json, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $name,
            $slug,
            $description,
            $host,
            $sectionKey,
            json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            $options === [] ? null : json_encode($options, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            $createdBy,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function updateMeta(int $id, string $name, string $slug, ?string $description, string $host): void
    {
        $host = SectionPatternHost::isValid($host) ? $host : SectionPatternHost::BOTH;
        $stmt = $this->pdo->prepare(
            'UPDATE ' . self::TABLE . ' SET name = ?, slug = ?, description = ?, host = ? WHERE id = ?'
        );
        $stmt->execute([$name, $slug, $description, $host, $id]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM ' . self::TABLE . ' WHERE id = ?');
        $stmt->execute([$id]);
    }
}
