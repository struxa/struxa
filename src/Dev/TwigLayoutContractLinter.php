<?php

declare(strict_types=1);

namespace App\Dev;

use App\Theme\PublicLayoutContract;

/**
 * Validates public layout inheritance against {@see PublicLayoutContract} without booting Twig.
 *
 * Scans core `templates/`, each `themes/{slug}/views/`, and each `plugins/{slug}/views/`.
 */
final class TwigLayoutContractLinter
{
    private const EXTENDS_STRING = '/\{%-?\s*extends\s+(["\'])([^"\']+)\1\s*-?%\}/';

    private const EXTENDS_FUNC = '/\{%-?\s*extends\s+(public_layout|theme_layout)\(\)\s*-?%\}/';

    /** @var list<string> */
    private array $loaderRoots = [];

    private bool $lintPluginViews = true;

    public function __construct(
        private readonly string $projectRoot,
    ) {
        $root = rtrim($this->projectRoot, '/\\');
        $this->loaderRoots[] = $root . DIRECTORY_SEPARATOR . 'templates';

        foreach (glob($root . DIRECTORY_SEPARATOR . 'themes' . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . 'views', GLOB_ONLYDIR) ?: [] as $views) {
            $this->loaderRoots[] = $views;
        }

        $pluginsRoot = $root . DIRECTORY_SEPARATOR . 'plugins';
        $this->lintPluginViews = !(
            is_file($pluginsRoot . DIRECTORY_SEPARATOR . '.gitkeep')
            && !is_file($pluginsRoot . DIRECTORY_SEPARATOR . '.struxa-bundle-plugins')
        );
        if ($this->lintPluginViews) {
            foreach (glob($pluginsRoot . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . 'views', GLOB_ONLYDIR) ?: [] as $pViews) {
                $this->loaderRoots[] = $pViews;
            }
        }
    }

    /**
     * @return list<TwigLayoutContractIssue>
     */
    public function lint(bool $warnDuplicates = true): array
    {
        $issues = [];

        $forbidden = $this->projectRoot . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'layouts' . DIRECTORY_SEPARATOR . 'base.twig';
        if (is_file($forbidden)) {
            $issues[] = new TwigLayoutContractIssue(
                'error',
                'forbidden_core_layouts_base',
                'Core must not define templates/layouts/base.twig — it shadows every theme storefront layout (see PublicLayoutContract). Remove or rename this file.',
                $forbidden,
                null,
            );
        }

        foreach ($this->iterTwigFiles() as $absPath => $zone) {
            $parsed = $this->parseFirstExtends($absPath);
            if ($parsed === null) {
                continue;
            }

            if ($parsed['kind'] === 'function') {
                $issues = array_merge($issues, $this->checkFunctionExtends($absPath, $zone, $parsed['name']));
                continue;
            }

            $parent = $parsed['value'];
            $issues = array_merge($issues, $this->checkStringExtends($absPath, $zone, $parent));
        }

        if ($warnDuplicates) {
            $issues = array_merge($issues, $this->findCoreThemeDuplicates());
        }

        return $issues;
    }

    /**
     * @return list<TwigLayoutContractIssue>
     */
    private function checkFunctionExtends(string $file, string $zone, string $func): array
    {
        $issues = [];
        if ($zone === 'theme' && $func === 'public_layout') {
            $issues[] = new TwigLayoutContractIssue(
                'error',
                'theme_extends_public_layout',
                'Theme storefront views must extend layouts/base.twig or {% extends theme_layout() %} — not public_layout() (core marketing shell).',
                $file,
                'public_layout()',
            );
        }
        if (str_starts_with($zone, 'plugin_') && $func === 'theme_layout') {
            $issues[] = new TwigLayoutContractIssue(
                'error',
                'plugin_extends_theme_layout',
                'Plugin templates must not extend theme_layout() — that binds to the active theme shell. Use public/root.twig, base.twig, or public_layout().',
                $file,
                'theme_layout()',
            );
        }

        return $issues;
    }

    /**
     * @return list<TwigLayoutContractIssue>
     */
    private function checkStringExtends(string $file, string $zone, string $parent): array
    {
        $issues = [];

        if ($zone === 'theme') {
            if ($parent === PublicLayoutContract::LEGACY_BASE_ALIAS) {
                $issues[] = new TwigLayoutContractIssue(
                    'error',
                    'theme_extends_core_base_alias',
                    'Theme views must extend layouts/base.twig (or theme_layout()), not base.twig — base.twig resolves to core first and uses the marketing shell.',
                    $file,
                    $parent,
                );
            }
            if ($parent === PublicLayoutContract::PUBLIC_ROOT) {
                $issues[] = new TwigLayoutContractIssue(
                    'error',
                    'theme_extends_public_root',
                    'Theme storefront templates should extend layouts/base.twig, not public/root.twig directly.',
                    $file,
                    $parent,
                );
            }
        }

        if (($zone === 'plugin_public' || $zone === 'plugin_other' || $zone === 'plugin_admin') && $parent === PublicLayoutContract::THEME_SHELL) {
            $issues[] = new TwigLayoutContractIssue(
                'error',
                'plugin_extends_theme_shell',
                'Plugin templates must extend public/root.twig or base.twig (or admin/base.twig in admin/) — not layouts/base.twig (theme storefront shell).',
                $file,
                $parent,
            );
        }

        if ($zone === 'core_public' && $parent === PublicLayoutContract::THEME_SHELL) {
            $issues[] = new TwigLayoutContractIssue(
                'error',
                'core_extends_theme_shell',
                'Core templates must not extend layouts/base.twig — that is the theme storefront shell. Use base.twig or public/root.twig.',
                $file,
                $parent,
            );
        }

        if (!$this->templateExistsOnFilesystem($parent)) {
            $issues[] = new TwigLayoutContractIssue(
                'error',
                'unresolved_extends',
                'No template file found for this parent in templates/, themes/*/views/, or plugins/*/views/ (Twig may still resolve via namespaces at runtime — fix path or add file).',
                $file,
                $parent,
            );
        }

        return $issues;
    }

    /**
     * @return list<TwigLayoutContractIssue>
     */
    private function findCoreThemeDuplicates(): array
    {
        $issues = [];
        $templatesDir = $this->projectRoot . DIRECTORY_SEPARATOR . 'templates';

        foreach (glob($this->projectRoot . DIRECTORY_SEPARATOR . 'themes' . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . 'views', GLOB_ONLYDIR) ?: [] as $themeViews) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($themeViews, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $info) {
                if (!$info->isFile() || !str_ends_with(strtolower($info->getFilename()), '.twig')) {
                    continue;
                }
                $abs = $info->getPathname();
                $rel = $this->relativeToViewsRoot($abs, $themeViews);
                if ($rel === null || str_starts_with($rel, 'admin' . DIRECTORY_SEPARATOR)) {
                    continue;
                }
                $coreCandidate = $templatesDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
                if (is_file($coreCandidate)) {
                    $issues[] = new TwigLayoutContractIssue(
                        'warning',
                        'duplicate_template_core_and_theme',
                        sprintf(
                            'Same logical path in core and theme (loader prefers core). Core: %s',
                            $this->shortPath($coreCandidate)
                        ),
                        $abs,
                        null,
                    );
                }
            }
        }

        return $issues;
    }

    private function shortPath(string $abs): string
    {
        $r = $this->projectRoot;
        if (str_starts_with($abs, $r)) {
            return ltrim(substr($abs, strlen($r)), DIRECTORY_SEPARATOR . '/');
        }

        return $abs;
    }

    private function relativeToViewsRoot(string $file, string $viewsRoot): ?string
    {
        $viewsRoot = rtrim(str_replace('\\', '/', realpath($viewsRoot) ?: $viewsRoot), '/');
        $fileNorm = str_replace('\\', '/', $file);
        if (!str_starts_with($fileNorm, $viewsRoot . '/')) {
            return null;
        }

        return substr($fileNorm, strlen($viewsRoot) + 1);
    }

    private function templateExistsOnFilesystem(string $logicalName): bool
    {
        $logicalName = str_replace('\\', '/', $logicalName);
        if ($logicalName === '' || str_contains($logicalName, '..')) {
            return false;
        }

        foreach ($this->loaderRoots as $root) {
            $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $logicalName);
            if (is_file($path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, string> path => zone
     */
    private function iterTwigFiles(): array
    {
        $out = [];
        $root = rtrim($this->projectRoot, '/\\');
        $templates = $root . DIRECTORY_SEPARATOR . 'templates';

        $this->walkTwig($templates, $out, function (string $abs) use ($templates): string {
            $rest = substr($abs, strlen($templates) + 1);

            return str_starts_with($rest, 'admin' . DIRECTORY_SEPARATOR) ? 'core_admin' : 'core_public';
        });

        foreach (glob($root . DIRECTORY_SEPARATOR . 'themes' . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . 'views', GLOB_ONLYDIR) ?: [] as $views) {
            $this->walkTwig($views, $out, static fn (): string => 'theme');
        }

        if ($this->lintPluginViews) {
            foreach (glob($root . DIRECTORY_SEPARATOR . 'plugins' . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . 'views', GLOB_ONLYDIR) ?: [] as $pViews) {
                $this->walkTwig($pViews, $out, function (string $abs) use ($pViews): string {
                    $rest = substr($abs, strlen($pViews) + 1);

                    return str_starts_with($rest, 'public' . DIRECTORY_SEPARATOR) ? 'plugin_public' : (str_starts_with($rest, 'admin' . DIRECTORY_SEPARATOR) ? 'plugin_admin' : 'plugin_other');
                });
            }
        }

        return $out;
    }

    /**
     * @param array<string, string> $out
     * @param callable(string): string $zoneFn
     */
    private function walkTwig(string $dir, array &$out, callable $zoneFn): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $info) {
            if (!$info->isFile() || !str_ends_with(strtolower($info->getFilename()), '.twig')) {
                continue;
            }
            $abs = $info->getPathname();
            $out[$abs] = $zoneFn($abs);
        }
    }

    /**
     * @return array{kind: 'string', value: string}|array{kind: 'function', name: string}|null
     */
    private function parseFirstExtends(string $file): ?array
    {
        $head = file_get_contents($file, false, null, 0, 12000);
        if ($head === false) {
            return null;
        }
        $lines = preg_split("/\r\n|\n|\r/", $head) ?: [];
        $snippet = implode("\n", array_slice($lines, 0, 50));

        if (preg_match(self::EXTENDS_FUNC, $snippet, $m)) {
            return ['kind' => 'function', 'name' => $m[1]];
        }
        if (preg_match(self::EXTENDS_STRING, $snippet, $m)) {
            return ['kind' => 'string', 'value' => $m[2]];
        }

        return null;
    }
}
