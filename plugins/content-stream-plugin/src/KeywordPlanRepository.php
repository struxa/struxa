<?php

declare(strict_types=1);

namespace ContentStreamPlugin;

use PDO;

final class KeywordPlanRepository
{
    private const PLANS = 'cms_plugin_content_stream_keyword_plans';
    private const ITEMS = 'cms_plugin_content_stream_keyword_plan_items';

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function plansTableExists(): bool
    {
        try {
            $this->pdo->query('SELECT 1 FROM ' . self::PLANS . ' LIMIT 1');

            return true;
        } catch (\PDOException) {
            return false;
        }
    }

    /**
     * @param list<array{
     *   day: int,
     *   primary_keyword: string,
     *   search_intent?: string,
     *   title: string,
     *   outline: list<string>,
     *   meta_description?: string,
     *   opportunity_score?: float|int|null,
     *   score_rationale?: string
     * }> $items
     */
    public function createPlan(
        string $yearMonth,
        ?string $label,
        ?string $domain,
        array $analysis,
        array $items,
    ): int {
        $analysisJson = json_encode($analysis, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO ' . self::PLANS . ' (plan_month, label, domain, analysis_json) VALUES (?, ?, ?, ?)'
            );
            $stmt->execute([
                $yearMonth,
                $label !== null && $label !== '' ? $label : null,
                $domain !== null && $domain !== '' ? $domain : null,
                $analysisJson,
            ]);
            $planId = (int) $this->pdo->lastInsertId();

            $ins = $this->pdo->prepare(
                'INSERT INTO ' . self::ITEMS . ' (plan_id, day_index, primary_keyword, search_intent, title, outline_json, meta_description, opportunity_score, score_rationale)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );

            foreach ($items as $row) {
                $day = (int) ($row['day'] ?? 0);
                if ($day < 1) {
                    continue;
                }
                $outline = $row['outline'] ?? [];
                $outlineJson = is_array($outline)
                    ? json_encode($outline, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)
                    : null;
                $score = $row['opportunity_score'] ?? null;
                $scoreVal = is_numeric($score) ? round((float) $score, 2) : null;

                $ins->execute([
                    $planId,
                    $day,
                    substr((string) ($row['primary_keyword'] ?? ''), 0, 512),
                    isset($row['search_intent']) ? substr((string) $row['search_intent'], 0, 32) : null,
                    substr((string) ($row['title'] ?? ''), 0, 500),
                    $outlineJson,
                    isset($row['meta_description']) ? substr((string) $row['meta_description'], 0, 320) : null,
                    $scoreVal,
                    isset($row['score_rationale']) ? (string) $row['score_rationale'] : null,
                ]);
            }

            $this->pdo->commit();

            return $planId;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listPlans(int $limit = 40): array
    {
        if (!$this->plansTableExists()) {
            return [];
        }
        $lim = max(1, min(100, $limit));
        $stmt = $this->pdo->query(
            'SELECT id, plan_month, label, domain, created_at FROM ' . self::PLANS . ' ORDER BY id DESC LIMIT ' . $lim
        );
        $out = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (is_array($row)) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * @return array{plan: array<string, mixed>|null, items: list<array<string, mixed>>}
     */
    public function findPlanWithItems(int $id): array
    {
        if (!$this->plansTableExists()) {
            return ['plan' => null, 'items' => []];
        }
        $stmt = $this->pdo->prepare('SELECT * FROM ' . self::PLANS . ' WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $plan = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($plan)) {
            return ['plan' => null, 'items' => []];
        }

        $stmt = $this->pdo->prepare(
            'SELECT * FROM ' . self::ITEMS . ' WHERE plan_id = ? ORDER BY day_index ASC'
        );
        $stmt->execute([$id]);
        $items = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (is_array($row)) {
                $items[] = $row;
            }
        }

        return ['plan' => $plan, 'items' => $items];
    }
}
