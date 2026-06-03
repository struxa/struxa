<?php

declare(strict_types=1);

namespace StruxaAdmin;

use App\Dist\StruxaDistCatalogClient;

/**
 * Seeds approved catalog submissions from an existing struxa-dist/repo.json on disk.
 */
final class CatalogRepoJsonImporter
{
    public function __construct(
        private readonly CatalogSettings $settings,
        private readonly CatalogSubmissionRepository $submissions,
        private readonly CatalogPublisher $publisher,
    ) {
    }

    /**
     * @return array{
     *   ok: true,
     *   imported: int,
     *   updated: int,
     *   skipped: int,
     *   slugs: list<string>
     * }|array{ok: false, error: string}
     */
    public function importFromDistRepoJson(?int $reviewedBy, bool $updateExisting = false): array
    {
        $path = $this->settings->distRoot() . '/repo.json';
        if (!is_readable($path)) {
            return ['ok' => false, 'error' => 'repo.json not found at ' . $path];
        }

        try {
            /** @var mixed $data */
            $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return ['ok' => false, 'error' => 'Invalid repo.json: ' . $e->getMessage()];
        }
        if (!is_array($data)) {
            return ['ok' => false, 'error' => 'repo.json must be a JSON object.'];
        }

        $data = (new StruxaDistCatalogClient($this->settings->projectRoot()))->normalizeCatalogData($data);

        $imported = 0;
        $updated = 0;
        $skipped = 0;
        /** @var list<string> $slugs */
        $slugs = [];

        foreach ($this->entriesFromCatalog($data, SubmissionKind::THEME, 'themes') as $entry) {
            $result = $this->importEntry($entry, SubmissionKind::THEME, $reviewedBy, $updateExisting);
            if ($result === 'imported') {
                ++$imported;
                $slugs[] = $entry['slug'];
            } elseif ($result === 'updated') {
                ++$updated;
                $slugs[] = $entry['slug'];
            } else {
                ++$skipped;
            }
        }

        foreach ($this->entriesFromCatalog($data, SubmissionKind::PLUGIN, 'plugins') as $entry) {
            $result = $this->importEntry($entry, SubmissionKind::PLUGIN, $reviewedBy, $updateExisting);
            if ($result === 'imported') {
                ++$imported;
                $slugs[] = $entry['slug'];
            } elseif ($result === 'updated') {
                ++$updated;
                $slugs[] = $entry['slug'];
            } else {
                ++$skipped;
            }
        }

        $regen = $this->publisher->regenerateCatalog();
        if (!$regen['ok']) {
            return ['ok' => false, 'error' => 'Imported rows but catalog regeneration failed: ' . $regen['error']];
        }

        return [
            'ok' => true,
            'imported' => $imported,
            'updated' => $updated,
            'skipped' => $skipped,
            'slugs' => $slugs,
        ];
    }

    /**
     * @param array<string, mixed> $catalog
     * @return list<array<string, mixed>>
     */
    private function entriesFromCatalog(array $catalog, string $kind, string $key): array
    {
        if (!isset($catalog[$key]) || !is_array($catalog[$key])) {
            return [];
        }

        $out = [];
        foreach ($catalog[$key] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $slug = strtolower(trim((string) ($row['slug'] ?? '')));
            if ($slug === '') {
                continue;
            }
            $out[] = $row;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $entry
     * @return 'imported'|'updated'|'skipped'
     */
    private function importEntry(array $entry, string $kind, ?int $reviewedBy, bool $updateExisting): string
    {
        $slug = strtolower(trim((string) ($entry['slug'] ?? '')));
        $name = trim((string) ($entry['name'] ?? $slug));
        $version = trim((string) ($entry['version'] ?? '1.0.0'));
        $description = trim((string) ($entry['description'] ?? ''));
        $author = trim((string) ($entry['author'] ?? ''));
        $gitRepoUrl = trim((string) ($entry['repository_url'] ?? ''));
        if ($gitRepoUrl === '') {
            $gitRepoUrl = 'https://github.com/struxa/' . rawurlencode($slug);
        }

        $manifest = [
            'slug' => $slug,
            'name' => $name,
            'version' => $version,
            'description' => $description,
            'author' => $author,
        ];
        $req = $entry['requires_cms_version'] ?? null;
        if (is_string($req) && trim($req) !== '') {
            $manifest['requires_cms_version'] = trim($req);
        }
        if ($gitRepoUrl !== '') {
            $manifest['repository_url'] = $gitRepoUrl;
        }

        $existing = $this->submissions->findBySlugAndKind($slug, $kind);
        if ($existing !== null) {
            if (!$updateExisting) {
                return 'skipped';
            }
            $this->submissions->updateApprovedImport(
                $existing->id,
                $gitRepoUrl,
                'main',
                $name,
                $version,
                $description,
                $author,
                $manifest,
                $reviewedBy,
            );

            return 'updated';
        }

        $this->submissions->insertApprovedImport(
            $kind,
            $gitRepoUrl,
            'main',
            $slug,
            $name,
            $version,
            $description,
            $author,
            $manifest,
            $reviewedBy,
        );

        return 'imported';
    }
}
