<?php

declare(strict_types=1);

namespace App\Config;

/**
 * Human-readable diff between local structure export and an incoming package payload.
 */
final class ConfigDiffService
{
    /**
     * @param array<string, mixed> $local from StructureCollector::collectPartial
     * @param array<string, mixed> $incoming structure section of a config package
     * @param list<string> $scopes
     * @return array{
     *     summary: array{add: int, update: int, remove: int, unchanged: int},
     *     sections: list<array{scope: string, title: string, lines: list<string>}>
     * }
     */
    public function diff(array $local, array $incoming, array $scopes): array
    {
        $scopes = ConfigPackageRegistry::normalizeScopes($scopes);
        $sections = [];
        $add = 0;
        $update = 0;
        $remove = 0;
        $unchanged = 0;

        foreach ($scopes as $scope) {
            $section = match ($scope) {
                'content_types' => $this->diffContentTypes($local, $incoming),
                'menus' => $this->diffMenus($local, $incoming),
                'settings' => $this->diffSettings($local, $incoming),
                'meta' => $this->diffMeta($local, $incoming),
                'roles' => $this->diffRoles($local, $incoming),
                'mobile' => $this->diffMobile($local, $incoming),
                'commerce' => $this->diffCommerce($local, $incoming),
                'entries' => $this->diffEntries($local, $incoming),
                default => ['title' => $scope, 'lines' => []],
            };
            foreach ($section['lines'] as $line) {
                if (str_starts_with($line, '+ ')) {
                    ++$add;
                } elseif (str_starts_with($line, '~ ')) {
                    ++$update;
                } elseif (str_starts_with($line, '− ') || str_starts_with($line, '- ')) {
                    ++$remove;
                } else {
                    ++$unchanged;
                }
            }
            if ($section['lines'] !== []) {
                $sections[] = [
                    'scope' => $scope,
                    'title' => $section['title'],
                    'lines' => $section['lines'],
                ];
            }
        }

        return [
            'summary' => [
                'add' => $add,
                'update' => $update,
                'remove' => $remove,
                'unchanged' => $unchanged,
            ],
            'sections' => $sections,
        ];
    }

    /**
     * @param array<string, mixed> $local
     * @param array<string, mixed> $incoming
     * @return array{title: string, lines: list<string>}
     */
    private function diffContentTypes(array $local, array $incoming): array
    {
        $lines = [];
        $localMap = $this->indexBySlug($local['content_types'] ?? [], 'slug');
        $inMap = $this->indexBySlug($incoming['content_types'] ?? [], 'slug');

        foreach ($inMap as $slug => $row) {
            if (!isset($localMap[$slug])) {
                $fields = is_array($row['fields'] ?? null) ? count($row['fields']) : 0;
                $lines[] = '+ Content type "' . $slug . '" (' . $fields . ' fields)';

                continue;
            }
            $loc = $localMap[$slug];
            $changes = [];
            if (($loc['name'] ?? '') !== ($row['name'] ?? '')) {
                $changes[] = 'name';
            }
            $lf = $this->fieldKeys($loc);
            $inf = $this->fieldKeys($row);
            $newFields = array_diff($inf, $lf);
            $goneFields = array_diff($lf, $inf);
            if ($newFields !== []) {
                $changes[] = 'fields +' . implode(', +', $newFields);
            }
            if ($goneFields !== []) {
                $changes[] = 'fields −' . implode(', −', $goneFields);
            }
            if ($changes === []) {
                $lines[] = '= "' . $slug . '" unchanged';

                continue;
            }
            $lines[] = '~ "' . $slug . '": ' . implode('; ', $changes);
        }
        foreach ($localMap as $slug => $_) {
            if (!isset($inMap[$slug])) {
                $lines[] = '− "' . $slug . '" only on this site (not in package)';
            }
        }

        return ['title' => 'Content types', 'lines' => $lines];
    }

    /**
     * @param array<string, mixed> $row
     * @return list<string>
     */
    private function fieldKeys(array $row): array
    {
        $keys = [];
        foreach ($row['fields'] ?? [] as $f) {
            if (is_array($f) && isset($f['field_key'])) {
                $keys[] = (string) $f['field_key'];
            }
        }
        sort($keys);

        return $keys;
    }

    /**
     * @param array<string, mixed> $local
     * @param array<string, mixed> $incoming
     * @return array{title: string, lines: list<string>}
     */
    private function diffMenus(array $local, array $incoming): array
    {
        $lines = [];
        $localMenus = is_array($local['menus'] ?? null) ? $local['menus'] : [];
        $inMenus = is_array($incoming['menus'] ?? null) ? $incoming['menus'] : [];
        $localByLoc = [];
        foreach ($localMenus as $m) {
            if (is_array($m)) {
                $localByLoc[(string) ($m['location'] ?? '')] = $m;
            }
        }
        foreach ($inMenus as $m) {
            if (!is_array($m)) {
                continue;
            }
            $loc = (string) ($m['location'] ?? '');
            $inCount = is_array($m['items'] ?? null) ? count($m['items']) : 0;
            $locRow = $localByLoc[$loc] ?? null;
            $locCount = $locRow !== null && is_array($locRow['items'] ?? null) ? count($locRow['items']) : 0;
            if ($locRow === null) {
                $lines[] = '+ Menu "' . $loc . '" with ' . $inCount . ' items';

                continue;
            }
            if ($inCount !== $locCount) {
                $lines[] = '~ Menu "' . $loc . '": ' . $locCount . ' → ' . $inCount . ' items';
            } else {
                $lines[] = '= Menu "' . $loc . '" (' . $inCount . ' items)';
            }
        }

        return ['title' => 'Menus', 'lines' => $lines];
    }

    /**
     * @param array<string, mixed> $local
     * @param array<string, mixed> $incoming
     * @return array{title: string, lines: list<string>}
     */
    private function diffSettings(array $local, array $incoming): array
    {
        return $this->diffKeyValue(
            'Site settings',
            is_array($local['settings'] ?? null) ? $local['settings'] : [],
            is_array($incoming['settings'] ?? null) ? $incoming['settings'] : [],
            ['google_oauth_client_secret']
        );
    }

    /**
     * @param array<string, mixed> $local
     * @param array<string, mixed> $incoming
     * @return array{title: string, lines: list<string>}
     */
    private function diffMeta(array $local, array $incoming): array
    {
        $lines = [];
        $lt = (string) ($local['active_theme_slug'] ?? '');
        $it = (string) ($incoming['active_theme_slug'] ?? '');
        if ($it !== '' && $it !== $lt) {
            $lines[] = '~ Active theme: ' . ($lt !== '' ? $lt : '(none)') . ' → ' . $it;
        } elseif ($it !== '') {
            $lines[] = '= Active theme "' . $it . '"';
        }

        return ['title' => 'Meta (theme & plugins)', 'lines' => $lines];
    }

    /**
     * @param array<string, mixed> $local
     * @param array<string, mixed> $incoming
     * @return array{title: string, lines: list<string>}
     */
    private function diffRoles(array $local, array $incoming): array
    {
        $lines = [];
        $localMap = $this->indexBySlug($local['roles'] ?? [], 'slug');
        $inMap = $this->indexBySlug($incoming['roles'] ?? [], 'slug');
        foreach ($inMap as $slug => $row) {
            $perms = $this->sortedSlugs($row['permission_slugs'] ?? []);
            if (!isset($localMap[$slug])) {
                $lines[] = '+ Role "' . $slug . '" (' . count($perms) . ' permissions)';

                continue;
            }
            $locPerms = $this->sortedSlugs($localMap[$slug]['permission_slugs'] ?? []);
            if ($perms !== $locPerms) {
                $lines[] = '~ Role "' . $slug . '": permissions changed';
            } else {
                $lines[] = '= Role "' . $slug . '"';
            }
        }
        foreach ($localMap as $slug => $row) {
            if (!isset($inMap[$slug]) && empty($row['is_system'])) {
                $lines[] = '− Role "' . $slug . '" only on this site';
            }
        }

        return ['title' => 'Roles', 'lines' => $lines];
    }

    /**
     * @param array<string, mixed> $local
     * @param array<string, mixed> $incoming
     * @return array{title: string, lines: list<string>}
     */
    private function diffMobile(array $local, array $incoming): array
    {
        return $this->diffKeyValue(
            'Mobile app settings',
            is_array($local['mobile_settings'] ?? null) ? $local['mobile_settings'] : [],
            is_array($incoming['mobile_settings'] ?? null) ? $incoming['mobile_settings'] : [],
            []
        );
    }

    /**
     * @param array<string, mixed> $local
     * @param array<string, mixed> $incoming
     * @return array{title: string, lines: list<string>}
     */
    private function diffCommerce(array $local, array $incoming): array
    {
        $lines = [];
        $lc = is_array($local['commerce'] ?? null) ? $local['commerce'] : [];
        $ic = is_array($incoming['commerce'] ?? null) ? $incoming['commerce'] : [];

        $lines = array_merge($lines, $this->diffNamedList(
            'Shipping zone',
            $lc['shipping_zones'] ?? [],
            $ic['shipping_zones'] ?? [],
            'name'
        ));
        $lines = array_merge($lines, $this->diffNamedList(
            'Tax rate',
            $lc['tax_rates'] ?? [],
            $ic['tax_rates'] ?? [],
            'country_code'
        ));
        $lines = array_merge($lines, $this->diffNamedList(
            'Coupon',
            $lc['coupons'] ?? [],
            $ic['coupons'] ?? [],
            'code'
        ));

        return ['title' => 'Commerce', 'lines' => $lines];
    }

    /**
     * @param array<string, mixed> $local
     * @param array<string, mixed> $incoming
     * @return array{title: string, lines: list<string>}
     */
    private function diffEntries(array $local, array $incoming): array
    {
        $lines = [];
        $localEntries = is_array($local['content_entries'] ?? null) ? $local['content_entries'] : [];
        $inEntries = is_array($incoming['content_entries'] ?? null) ? $incoming['content_entries'] : [];
        $localCount = count($localEntries);
        $inCount = count($inEntries);
        if ($inCount > $localCount) {
            $lines[] = '+ ' . ($inCount - $localCount) . ' more entries in package than exported locally';
        } elseif ($inCount < $localCount) {
            $lines[] = '− Package has fewer entries (' . $inCount . ') than local sample (' . $localCount . ')';
        } else {
            $lines[] = '= ' . $inCount . ' entries in package (counts match sample)';
        }

        return ['title' => 'Content entries', 'lines' => $lines];
    }

    /**
     * @param list<mixed> $local
     * @param list<mixed> $incoming
     * @param list<string> $redactKeys
     * @return array{title: string, lines: list<string>}
     */
    private function diffKeyValue(string $title, array $local, array $incoming, array $redactKeys): array
    {
        $lines = [];
        $redact = array_flip($redactKeys);
        foreach ($incoming as $k => $v) {
            if (!is_string($k)) {
                continue;
            }
            $vs = is_string($v) ? $v : json_encode($v);
            $ls = array_key_exists($k, $local) ? (is_string($local[$k]) ? $local[$k] : json_encode($local[$k])) : null;
            if ($ls === null) {
                $lines[] = '+ ' . $k . (isset($redact[$k]) ? ' (set)' : ': ' . $this->short($vs));

                continue;
            }
            if ($ls !== $vs) {
                $lines[] = '~ ' . $k . (isset($redact[$k]) ? ' (changed)' : ': ' . $this->short($ls) . ' → ' . $this->short($vs));
            }
        }
        foreach ($local as $k => $_) {
            if (is_string($k) && !array_key_exists($k, $incoming)) {
                $lines[] = '− ' . $k . ' only on this site';
            }
        }

        return ['title' => $title, 'lines' => $lines];
    }

    /**
     * @param list<mixed> $local
     * @param list<mixed> $incoming
     * @return list<string>
     */
    private function diffNamedList(string $label, array $local, array $incoming, string $key): array
    {
        $lines = [];
        $localMap = $this->indexBySlug($local, $key);
        $inMap = $this->indexBySlug($incoming, $key);
        foreach ($inMap as $id => $_) {
            if (!isset($localMap[$id])) {
                $lines[] = '+ ' . $label . ' "' . $id . '"';
            } else {
                $lines[] = '~ ' . $label . ' "' . $id . '" (update)';
            }
        }
        foreach ($localMap as $id => $_) {
            if (!isset($inMap[$id])) {
                $lines[] = '− ' . $label . ' "' . $id . '" only on this site';
            }
        }

        return $lines;
    }

    /**
     * @param list<mixed> $rows
     * @return array<string, array<string, mixed>>
     */
    private function indexBySlug(array $rows, string $key): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $slug = (string) ($row[$key] ?? '');
            if ($slug !== '') {
                $out[$slug] = $row;
            }
        }

        return $out;
    }

    /**
     * @param mixed $list
     * @return list<string>
     */
    private function sortedSlugs(mixed $list): array
    {
        if (!is_array($list)) {
            return [];
        }
        $out = [];
        foreach ($list as $v) {
            if (is_string($v) && $v !== '') {
                $out[] = $v;
            }
        }
        sort($out);

        return $out;
    }

    private function short(string $s): string
    {
        $s = trim($s);
        if (mb_strlen($s) > 48) {
            return mb_substr($s, 0, 45) . '…';
        }

        return $s === '' ? '(empty)' : $s;
    }
}
