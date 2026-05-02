<?php

declare(strict_types=1);

namespace App\Plugin;

use PDO;

final class PluginRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function findBySlug(string $slug): ?PluginRecord
    {
        $stmt = $this->pdo->prepare('SELECT * FROM cms_plugins WHERE slug = ? LIMIT 1');
        $stmt->execute([$slug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? PluginRecord::fromRow($row) : null;
    }

    /**
     * @return list<PluginRecord>
     */
    public function allOrdered(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM cms_plugins ORDER BY name ASC');
        $out = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $out[] = PluginRecord::fromRow($row);
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    public function activeSlugs(): array
    {
        $stmt = $this->pdo->query('SELECT slug FROM cms_plugins WHERE is_active = 1 ORDER BY slug ASC');
        $out = [];
        while ($slug = $stmt->fetchColumn()) {
            $out[] = (string) $slug;
        }

        return $out;
    }

    /**
     * Upsert metadata from disk; preserves is_active for existing rows.
     */
    public function upsertFromManifest(PluginManifest $manifest): void
    {
        $existing = $this->findBySlug($manifest->slug);
        if ($existing === null) {
            $stmt = $this->pdo->prepare(
                'INSERT INTO cms_plugins (slug, name, version, is_active) VALUES (?, ?, ?, 0)'
            );
            $stmt->execute([
                $manifest->slug,
                $manifest->name,
                $manifest->version,
            ]);

            return;
        }

        $stmt = $this->pdo->prepare(
            'UPDATE cms_plugins SET name = ?, version = ?, updated_at = CURRENT_TIMESTAMP WHERE slug = ?'
        );
        $stmt->execute([$manifest->name, $manifest->version, $manifest->slug]);
    }

    public function setActive(string $slug, bool $active): void
    {
        $stmt = $this->pdo->prepare('UPDATE cms_plugins SET is_active = ?, updated_at = CURRENT_TIMESTAMP WHERE slug = ?');
        $stmt->execute([$active ? 1 : 0, $slug]);
    }

    public function deleteBySlug(string $slug): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM cms_plugins WHERE slug = ?');
        $stmt->execute([$slug]);
    }
}
