<?php

declare(strict_types=1);

namespace App\Twig;

use App\Cache\FileCache;
use App\Dist\GithubShowcaseStats;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class GithubShowcaseTwigExtension extends AbstractExtension
{
    public function __construct(
        private readonly string $projectRoot,
        private readonly FileCache $cache,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('github_showcase_stats', $this->githubShowcaseStats(...)),
        ];
    }

    /**
     * @return array{
     *   ok: bool,
     *   repo: string,
     *   latest_version: ?string,
     *   release_url: ?string,
     *   themes_count: int,
     *   plugins_count: int,
     *   lines_of_code: ?int,
     *   lines_of_code_label: ?string,
     *   stars: ?int,
     *   error: ?string
     * }
     */
    public function githubShowcaseStats(string $repoUrl = ''): array
    {
        if (trim($repoUrl) === '') {
            $repoUrl = 'https://github.com/struxa/struxa';
        }

        return (new GithubShowcaseStats($this->projectRoot, $this->cache))->forRepoUrl($repoUrl);
    }
}
