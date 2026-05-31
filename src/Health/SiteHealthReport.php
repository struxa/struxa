<?php

declare(strict_types=1);

namespace App\Health;

final class SiteHealthReport
{
    /**
     * @param list<SiteHealthCheck> $checks
     * @param array<string, string> $info
     */
    public function __construct(
        public readonly array $checks,
        public readonly array $info,
    ) {
    }

    public function overallStatus(): string
    {
        $statuses = array_map(static fn (SiteHealthCheck $c): string => $c->status, $this->checks);

        return SiteHealthStatus::worst($statuses);
    }

    /**
     * @return array{good: int, recommended: int, critical: int}
     */
    public function counts(): array
    {
        $out = ['good' => 0, 'recommended' => 0, 'critical' => 0];
        foreach ($this->checks as $check) {
            if (isset($out[$check->status])) {
                $out[$check->status]++;
            }
        }

        return $out;
    }

    /**
     * @return array<string, list<SiteHealthCheck>>
     */
    public function byGroup(): array
    {
        $out = [];
        foreach ($this->checks as $check) {
            $out[$check->group][] = $check;
        }

        return $out;
    }
}
