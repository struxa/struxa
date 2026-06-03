<?php

declare(strict_types=1);

namespace StruxaAdmin;

use App\Dist\StruxaDistCatalogClient;

/**
 * Public + admin catalog browsing (merged dist repo.json).
 */
final class CatalogBrowseService
{
    public function __construct(
        private readonly string $projectRoot,
        private readonly CatalogSubmissionRepository $submissions,
        private readonly CatalogSettings $settings,
    ) {
    }

    /**
     * @return array{ok: true, themes: list<array<string, mixed>>, plugins: list<array<string, mixed>>}|array{ok: false, error: string}
     */
    public function loadMergedCatalog(): array
    {
        $loaded = (new StruxaDistCatalogClient($this->projectRoot))->loadCatalog();
        if (!$loaded['ok']) {
            return ['ok' => false, 'error' => $loaded['error']];
        }
        $data = $loaded['data'];
        $themes = isset($data['themes']) && is_array($data['themes']) ? $this->normalizeRows($data['themes']) : [];
        $plugins = isset($data['plugins']) && is_array($data['plugins']) ? $this->normalizeRows($data['plugins']) : [];

        $screenshotBase = $this->settings->screenshotPublicBaseUrl();
        foreach ($this->submissions->listApproved() as $sub) {
            $entry = [
                'slug' => $sub->slug,
                'name' => $sub->name,
                'version' => $sub->version,
                'description' => $sub->description,
                'author' => $sub->author,
                'download_url' => $this->settings->trackedDownloadUrl($sub->kind, $sub->slug),
                'repository_url' => $sub->gitRepoUrl,
            ];
            if ($screenshotBase !== '' && $sub->screenshotPath !== null) {
                $entry['screenshot_url'] = rtrim($screenshotBase, '/')
                    . '/struxa-catalog/screenshot/' . rawurlencode(basename($sub->screenshotPath));
            }
            if ($sub->kind === SubmissionKind::THEME) {
                $themes = $this->upsert($themes, $entry);
            } else {
                $plugins = $this->upsert($plugins, $entry);
            }
        }

        return ['ok' => true, 'themes' => $themes, 'plugins' => $plugins];
    }

    /**
     * @return ?array<string, mixed>
     */
    public function findPackage(string $kind, string $slug): ?array
    {
        if (!SubmissionKind::isValid($kind)) {
            return null;
        }
        $slug = strtolower(trim($slug));
        if ($slug === '' || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
            return null;
        }

        $catalog = $this->loadMergedCatalog();
        if (!$catalog['ok']) {
            return null;
        }

        $list = $kind === SubmissionKind::THEME ? $catalog['themes'] : $catalog['plugins'];
        foreach ($list as $entry) {
            if (($entry['slug'] ?? '') === $slug) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * @param list<mixed> $rows
     * @return list<array<string, mixed>>
     */
    private function normalizeRows(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $slug = strtolower(trim((string) ($row['slug'] ?? '')));
            if ($slug === '') {
                continue;
            }
            $out[] = [
                'slug' => $slug,
                'name' => trim((string) ($row['name'] ?? $slug)),
                'version' => trim((string) ($row['version'] ?? '')),
                'description' => trim((string) ($row['description'] ?? '')),
                'author' => trim((string) ($row['author'] ?? '')),
                'download_url' => trim((string) ($row['download_url'] ?? '')),
                'requires_cms_version' => isset($row['requires_cms_version']) ? trim((string) $row['requires_cms_version']) : null,
                'repository_url' => isset($row['repository_url']) ? trim((string) $row['repository_url']) : null,
                'screenshot_url' => isset($row['screenshot_url']) ? trim((string) $row['screenshot_url']) : null,
            ];
        }
        usort($out, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $list
     * @param array<string, mixed> $entry
     * @return list<array<string, mixed>>
     */
    private function upsert(array $list, array $entry): array
    {
        $slug = (string) $entry['slug'];
        $out = [];
        $found = false;
        foreach ($list as $row) {
            if (($row['slug'] ?? '') === $slug) {
                $out[] = array_merge($row, $entry);
                $found = true;
            } else {
                $out[] = $row;
            }
        }
        if (!$found) {
            $out[] = $entry;
        }
        usort($out, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        return $out;
    }
}
