<?php

declare(strict_types=1);

namespace App\Form;

final class FormQuizScorer
{
    /**
     * @param array<string, mixed> $form
     * @param list<array<string, mixed>> $fields
     * @param list<array{field_key: string, value_text: string|null}> $values
     *
     * @return array{score: int, max_score: int, passed: bool, percent: int}
     */
    public static function score(array $form, array $fields, array $values): array
    {
        if (($form['form_type'] ?? 'standard') !== 'quiz') {
            return ['score' => 0, 'max_score' => 0, 'passed' => false, 'percent' => 0];
        }

        $valueMap = [];
        foreach ($values as $v) {
            $valueMap[(string) $v['field_key']] = (string) ($v['value_text'] ?? '');
        }

        $score = 0;
        $max = 0;
        foreach ($fields as $field) {
            $settings = is_array($field['settings'] ?? null) ? $field['settings'] : [];
            $points = (int) ($settings['quiz_points'] ?? 0);
            if ($points < 1) {
                continue;
            }
            $max += $points;
            $key = (string) ($field['field_key'] ?? '');
            $submitted = $valueMap[$key] ?? '';
            $correct = $settings['quiz_correct'] ?? '';
            if (is_array($correct)) {
                $submittedParts = array_map('trim', explode(',', $submitted));
                sort($submittedParts);
                $correctParts = array_map('strval', $correct);
                sort($correctParts);
                if ($submittedParts === $correctParts) {
                    $score += $points;
                }
            } elseif (strcasecmp(trim($submitted), trim((string) $correct)) === 0) {
                $score += $points;
            }
        }

        $settings = is_array($form['settings'] ?? null) ? $form['settings'] : [];
        $passPercent = max(0, min(100, (int) ($settings['quiz_pass_percent'] ?? 70)));
        $percent = $max > 0 ? (int) round(($score / $max) * 100) : 0;

        return [
            'score' => $score,
            'max_score' => $max,
            'passed' => $max > 0 && $percent >= $passPercent,
            'percent' => $percent,
        ];
    }

    /**
     * @param array<string, mixed> $form
     * @param array{score: int, max_score: int, passed: bool, percent: int} $result
     */
    public static function confirmationMessage(array $form, array $result): string
    {
        $settings = is_array($form['settings'] ?? null) ? $form['settings'] : [];
        if (!empty($settings['quiz_show_score'])) {
            $base = sprintf('You scored %d/%d (%d%%).', $result['score'], $result['max_score'], $result['percent']);
            if ($result['passed']) {
                return $base . ' ' . trim((string) ($settings['quiz_pass_message'] ?? 'Well done!'));
            }

            return $base . ' ' . trim((string) ($settings['quiz_fail_message'] ?? 'Try again.'));
        }

        if ($result['passed']) {
            return trim((string) ($settings['quiz_pass_message'] ?? 'Quiz passed!'));
        }

        return trim((string) ($settings['quiz_fail_message'] ?? 'Quiz not passed.'));
    }
}
