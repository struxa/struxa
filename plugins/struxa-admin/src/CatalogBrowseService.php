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
        $listedAtByKey = [];
        $approved = $this->submissions->listApproved();
        foreach ($approved as $sub) {
            $listedAtByKey[$sub->kind . ':' . strtolower($sub->slug)] = $sub->publishedAt
                ?? $sub->reviewedAt
                ?? $sub->createdAt;
        }
        foreach ($approved as $sub) {
            $entry = [
                'slug' => $sub->slug,
                'name' => $sub->name,
                'version' => $sub->version,
                'description' => $sub->description,
                'author' => $sub->author,
                'download_url' => $this->settings->trackedDownloadUrl($sub->kind, $sub->slug),
                'repository_url' => $sub->gitRepoUrl,
                'listed_at' => $listedAtByKey[$sub->kind . ':' . strtolower($sub->slug)] ?? null,
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

        $themes = $this->attachListedAt(SubmissionKind::THEME, $themes, $listedAtByKey);
        $plugins = $this->attachListedAt(SubmissionKind::PLUGIN, $plugins, $listedAtByKey);

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

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $list
     * @param array<string, string> $listedAtByKey
     *
     * @return list<array<string, mixed>>
     */
    private function attachListedAt(string $kind, array $list, array $listedAtByKey): array
    {
        $out = [];
        foreach ($list as $row) {
            $slug = strtolower(trim((string) ($row['slug'] ?? '')));
            $key = $kind . ':' . $slug;
            if (!isset($row['listed_at']) && isset($listedAtByKey[$key])) {
                $row['listed_at'] = $listedAtByKey[$key];
            }
            $out[] = $row;
        }

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

        return $out;
    }
}
