<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Line-based unified diff for admin revision compare (no external deps).
 */
final class LineDiff
{
    /**
     * @return list<string> Lines like unified diff (" ", "+", "-") without file headers.
     */
    public static function unified(string $a, string $b, int $maxLinesEach = 800): array
    {
        $la = self::normalizeLines($a, $maxLinesEach);
        $lb = self::normalizeLines($b, $maxLinesEach);
        if ($la === $lb) {
            return ['  (no line differences)'];
        }
        $ops = self::diffOps($la, $lb);
        $out = [];
        foreach ($ops as $op) {
            foreach ($op['lines'] as $ln) {
                $out[] = $op['mark'] . ' ' . $ln;
            }
        }

        return $out !== [] ? $out : ['  (no line differences)'];
    }

    /**
     * @return list<string>
     */
    private static function normalizeLines(string $s, int $max): array
    {
        $s = str_replace("\r\n", "\n", str_replace("\r", "\n", $s));
        $parts = explode("\n", $s);
        if (count($parts) > $max) {
            $parts = array_merge(array_slice($parts, 0, $max), ['… (truncated)']);
        }

        return $parts;
    }

    /**
     * @param list<string> $a
     * @param list<string> $b
     * @return list<array{mark: string, lines: list<string>}>
     */
    private static function diffOps(array $a, array $b): array
    {
        $n = count($a);
        $m = count($b);
        $dp = [];
        for ($i = 0; $i <= $n; $i++) {
            $dp[$i] = array_fill(0, $m + 1, 0);
        }
        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $dp[$i][$j] = $a[$i] === $b[$j]
                    ? 1 + $dp[$i + 1][$j + 1]
                    : max($dp[$i + 1][$j], $dp[$i][$j + 1]);
            }
        }
        $ops = [];
        $i = 0;
        $j = 0;
        while ($i < $n || $j < $m) {
            if ($i < $n && $j < $m && $a[$i] === $b[$j]) {
                $ops[] = ['mark' => ' ', 'lines' => [$a[$i]]];
                $i++;
                $j++;
            } elseif ($j < $m && ($i === $n || $dp[$i][$j + 1] >= $dp[$i + 1][$j])) {
                $ops[] = ['mark' => '+', 'lines' => [$b[$j]]];
                $j++;
            } elseif ($i < $n) {
                $ops[] = ['mark' => '-', 'lines' => [$a[$i]]];
                $i++;
            } else {
                break;
            }
        }

        return self::mergeAdjacentSameMark($ops);
    }

    /**
     * @param list<array{mark: string, lines: list<string>}> $ops
     * @return list<array{mark: string, lines: list<string>}>
     */
    private static function mergeAdjacentSameMark(array $ops): array
    {
        $out = [];
        foreach ($ops as $op) {
            $last = $out[count($out) - 1] ?? null;
            if ($last !== null && $last['mark'] === $op['mark']) {
                $out[count($out) - 1]['lines'][] = $op['lines'][0];

                continue;
            }
            $out[] = $op;
        }

        return $out;
    }
}
