<?php

declare(strict_types=1);

namespace App\Content;

use Twig\Environment;

/**
 * Resolves theme Twig templates for public content (per-type overrides).
 */
final class ContentViewTemplates
{
    /**
     * @param list<string> $candidates first existing wins
     */
    public static function resolve(Environment $env, array $candidates): string
    {
        foreach ($candidates as $name) {
            if ($env->getLoader()->exists($name)) {
                return $name;
            }
        }

        return $candidates[array_key_last($candidates)];
    }

    public static function contentShow(string $typeSlug): array
    {
        return [
            'content/' . $typeSlug . '/show.twig',
            'content/show.twig',
        ];
    }

    public static function contentIndex(string $typeSlug): array
    {
        return [
            'content/' . $typeSlug . '/index.twig',
            'content/index.twig',
        ];
    }

    /**
     * @return list<string>
     */
    public static function taxonomyArchive(string $typeSlug, string $taxonomySlug): array
    {
        return [
            'taxonomy/' . $typeSlug . '/' . $taxonomySlug . '/archive.twig',
            'taxonomy/' . $typeSlug . '/archive.twig',
            'taxonomy/archive.twig',
        ];
    }
}
