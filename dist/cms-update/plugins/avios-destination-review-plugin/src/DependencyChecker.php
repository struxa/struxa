<?php

declare(strict_types=1);

namespace AviosDestinationReviewPlugin;

use PDO;

/**
 * Confirms the "How Many Avios · Fare table" prerequisite plugin is installed AND
 * active AND its table is populated. Used both at boot (to gate route handlers)
 * and inside the admin view (to render an actionable warning).
 */
final class DependencyChecker
{
    public const HMA_SLUG = 'how-many-avios-plugin';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array{
     *   ok: bool,
     *   pluginActive: bool,
     *   tableExists: bool,
     *   destinationCount: int,
     *   message: string
     * }
     */
    public function check(): array
    {
        $pluginActive = $this->isPluginActive();
        $tableExists = $this->haveTable();
        $count = $tableExists ? $this->destinationCount() : 0;
        $ok = $pluginActive && $tableExists && $count > 0;

        $message = '';
        if (!$pluginActive) {
            $message = 'The "How Many Avios · Fare table" plugin must be activated before destination reviews can be generated.';
        } elseif (!$tableExists) {
            $message = 'The hma_fares table is missing. Run the How Many Avios plugin migrations.';
        } elseif ($count === 0) {
            $message = 'No destinations are loaded in hma_fares yet. Add some fares first.';
        }

        return [
            'ok' => $ok,
            'pluginActive' => $pluginActive,
            'tableExists' => $tableExists,
            'destinationCount' => $count,
            'message' => $message,
        ];
    }

    private function isPluginActive(): bool
    {
        try {
            $stmt = $this->pdo->prepare('SELECT is_active FROM cms_plugins WHERE slug = ? LIMIT 1');
            $stmt->execute([self::HMA_SLUG]);
            $v = $stmt->fetchColumn();

            return $v !== false && (int) $v === 1;
        } catch (\PDOException) {
            return false;
        }
    }

    private function haveTable(): bool
    {
        try {
            $this->pdo->query('SELECT 1 FROM hma_fares LIMIT 1');

            return true;
        } catch (\PDOException) {
            return false;
        }
    }

    private function destinationCount(): int
    {
        try {
            $stmt = $this->pdo->query('SELECT COUNT(DISTINCT iata) FROM hma_fares');

            return (int) $stmt->fetchColumn();
        } catch (\PDOException) {
            return 0;
        }
    }

    /**
     * Distinct (iata, destination) tuples for the admin picker. Pulled directly from
     * hma_fares so the list always reflects the upstream table.
     *
     * @return list<array{iata:string, destination:string}>
     */
    public function destinations(): array
    {
        if (!$this->haveTable()) {
            return [];
        }
        $stmt = $this->pdo->query(
            'SELECT iata, MIN(destination) AS destination
             FROM hma_fares
             GROUP BY iata
             ORDER BY MIN(destination) ASC'
        );
        /** @var list<array{iata:string, destination:string}> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return $rows;
    }

    /**
     * Lookup the canonical destination name for a single IATA the admin picked.
     */
    public function destinationName(string $iata): ?string
    {
        if (!$this->haveTable()) {
            return null;
        }
        $stmt = $this->pdo->prepare(
            'SELECT MIN(destination) FROM hma_fares WHERE iata = ?'
        );
        $stmt->execute([strtoupper($iata)]);
        $v = $stmt->fetchColumn();

        return $v === false || $v === null ? null : (string) $v;
    }
}
