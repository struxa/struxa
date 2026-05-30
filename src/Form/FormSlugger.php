<?php

declare(strict_types=1);

namespace App\Form;

use PDO;

final class FormSlugger
{
    public static function fromName(string $name): string
    {
        $slug = strtolower(trim($name));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
        $slug = trim($slug, '-');

        return $slug !== '' ? $slug : 'form';
    }

    public static function ensureUnique(PDO $pdo, string $base, ?int $excludeId = null): string
    {
        $slug = $base;
        $n = 2;
        while (!self::isAvailable($pdo, $slug, $excludeId)) {
            $slug = $base . '-' . $n;
            ++$n;
        }

        return $slug;
    }

    private static function isAvailable(PDO $pdo, string $slug, ?int $excludeId): bool
    {
        if ($excludeId !== null && $excludeId > 0) {
            $stmt = $pdo->prepare('SELECT id FROM cms_forms WHERE slug = ? AND id <> ? LIMIT 1');
            $stmt->execute([$slug, $excludeId]);
        } else {
            $stmt = $pdo->prepare('SELECT id FROM cms_forms WHERE slug = ? LIMIT 1');
            $stmt->execute([$slug]);
        }

        return $stmt->fetchColumn() === false;
    }
}
