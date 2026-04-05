<?php

declare(strict_types=1);

namespace App\SiteProfile;

use App\CmsVersion;
use PDO;
use PDOException;

/**
 * Single-row site / project metadata (id = 1).
 */
final class SiteProfileRepository
{
    private const TABLE = 'cms_site_profile';

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function tableExists(): bool
    {
        try {
            $this->pdo->query('SELECT 1 FROM ' . self::TABLE . ' LIMIT 1');

            return true;
        } catch (PDOException) {
            return false;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(): ?array
    {
        if (!$this->tableExists()) {
            return null;
        }
        $stmt = $this->pdo->query('SELECT * FROM ' . self::TABLE . ' WHERE id = 1 LIMIT 1');
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    public function syncInstalledVersion(): void
    {
        if (!$this->tableExists()) {
            return;
        }
        $stmt = $this->pdo->prepare(
            'UPDATE ' . self::TABLE . ' SET cms_version_installed = ? WHERE id = 1'
        );
        $stmt->execute([CmsVersion::CURRENT]);
    }

    /**
     * @param array<string, string> $fields allowed: project_name, environment_label
     */
    public function update(array $fields): void
    {
        if (!$this->tableExists() || $fields === []) {
            return;
        }
        $allowed = ['project_name', 'environment_label'];
        $sets = [];
        $vals = [];
        foreach ($allowed as $k) {
            if (!array_key_exists($k, $fields)) {
                continue;
            }
            $sets[] = $k . ' = ?';
            if ($k === 'environment_label') {
                $v = is_string($fields[$k]) ? trim($fields[$k]) : '';
                $vals[] = $v === '' ? null : $v;
            } else {
                $vals[] = (string) $fields[$k];
            }
        }
        if ($sets === []) {
            return;
        }
        $vals[] = 1;
        $sql = 'UPDATE ' . self::TABLE . ' SET ' . implode(', ', $sets) . ' WHERE id = ?';
        $this->pdo->prepare($sql)->execute($vals);
    }
}
