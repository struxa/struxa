<?php

declare(strict_types=1);

namespace StruxaAdmin;

use App\Plugin\PluginManifest;
use App\Plugin\PluginManifestParser;
use App\Theme\ThemeManifest;

final class CatalogSubmissionValidator
{
    public function __construct(
        private readonly GitHubRepoClient $github,
        private readonly CatalogSubmissionRepository $submissions,
    ) {
    }

    /**
     * @return array{
     *   ok: true,
     *   kind: string,
     *   slug: string,
     *   name: string,
     *   version: string,
     *   description: string,
     *   author: string,
     *   manifest: array<string, mixed>,
     *   git_repo_url: string,
     *   git_branch: string,
     *   owner: string,
     *   repo: string,
     *   package_root: string
     * }|array{ok: false, errors: list<string>}
     */
    public function validateSubmission(string $kind, string $gitRepoUrl, string $gitBranch = ''): array
    {
        $errors = [];
        if (!SubmissionKind::isValid($kind)) {
            $errors[] = 'Invalid submission type.';
        }

        $parsed = $this->github->parseRepoUrl($gitRepoUrl, $gitBranch);
        if (!$parsed['ok']) {
            return ['ok' => false, 'errors' => [$parsed['error']]];
        }

        $inspect = $this->github->inspectPackage(
            $parsed['owner'],
            $parsed['repo'],
            $parsed['branch'],
            $kind
        );
        if (!$inspect['ok']) {
            return ['ok' => false, 'errors' => [$inspect['error']]];
        }

        $manifest = $inspect['manifest'];
        if ($inspect['kind'] !== $kind) {
            $errors[] = 'Repository manifest type does not match your submission.';
        }

        if ($kind === SubmissionKind::PLUGIN) {
            $slug = strtolower(trim((string) ($manifest['slug'] ?? '')));
            $tmpDir = sys_get_temp_dir() . '/struxa-validate-' . bin2hex(random_bytes(4));
            @mkdir($tmpDir, 0700, true);
            $manifestFile = $tmpDir . '/plugin.json';
            file_put_contents($manifestFile, json_encode($manifest, JSON_THROW_ON_ERROR));
            $parser = new PluginManifestParser();
            $result = $parser->parseFile($manifestFile, $slug !== '' ? $slug : 'validate');
            @unlink($manifestFile);
            @rmdir($tmpDir);
            if (!$result['ok']) {
                $errors[] = 'plugin.json: ' . $result['error'];
            } else {
                $manifest = $this->manifestToArray($result['manifest']);
                $slug = $result['manifest']->slug;
            }
        } else {
            $slug = strtolower(trim((string) ($manifest['slug'] ?? '')));
            if (!ThemeManifest::isValidSlug($slug)) {
                $errors[] = 'theme.json slug must be a valid kebab-case identifier.';
            }
            if (trim((string) ($manifest['name'] ?? '')) === '') {
                $errors[] = 'theme.json requires a name.';
            }
            if (trim((string) ($manifest['version'] ?? '')) === '') {
                $errors[] = 'theme.json requires a version.';
            }
        }

        if ($slug === '' || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
            $errors[] = 'Manifest slug must be a non-empty kebab-case identifier.';
        }
        if (in_array($slug, ['admin', 'api', 'plugins', 'themes', 'login', 'install'], true)) {
            $errors[] = 'This slug is reserved and cannot be used.';
        }
        if ($this->submissions->slugExists($slug, $kind)) {
            $errors[] = 'A submission with this slug already exists. Choose a unique slug in your manifest.';
        }

        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors];
        }

        return [
            'ok' => true,
            'kind' => $kind,
            'slug' => $slug,
            'name' => trim((string) ($manifest['name'] ?? $slug)),
            'version' => trim((string) ($manifest['version'] ?? '1.0.0')),
            'description' => trim((string) ($manifest['description'] ?? '')),
            'author' => trim((string) ($manifest['author'] ?? '')),
            'manifest' => $manifest,
            'git_repo_url' => 'https://github.com/' . $parsed['owner'] . '/' . $parsed['repo'],
            'git_branch' => $inspect['branch'] ?? $parsed['branch'],
            'owner' => $parsed['owner'],
            'repo' => $parsed['repo'],
            'package_root' => $inspect['package_root'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function manifestToArray(PluginManifest $manifest): array
    {
        return [
            'name' => $manifest->name,
            'slug' => $manifest->slug,
            'version' => $manifest->version,
            'author' => $manifest->author,
            'description' => $manifest->description,
            'requires_cms_version' => $manifest->requiresCmsVersion,
            'repository_url' => $manifest->repositoryUrl ?? '',
        ];
    }
}
