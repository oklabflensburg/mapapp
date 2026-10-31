<?php

namespace App\Service;

final class BilingualTagTranslations
{
    public function __construct(
        private readonly TagTranslationCatalog $catalog,
        private readonly TagTranslationCatalogEditor $editor,
    ) {
    }

    /** @return array<string, array> */
    private function units(): array
    {
        $units = [];
        foreach (['de', 'en'] as $locale) {
            foreach ($this->catalog->entries($locale) as $row) {
                $id = $row['unit'];
                $units[$id] ??= [...$row, 'targets' => ['de' => '', 'en' => ''], 'ignores' => ['de' => false, 'en' => false]];
                $units[$id]['ignores'][$locale] = $row['ignore'];
                $units[$id]['ignore'] = in_array(true, $units[$id]['ignores'], true);
                if ($row['category'] === 'osm' || $units[$id]['category'] === '') {
                    $units[$id]['category'] = $row['category'];
                }
                $units[$id]['targets'][$locale] = $row['target'];
            }
        }
        // A retained contextual value also establishes the identity of its key.
        foreach ($units as $unit) {
            $id = 'key:'.$unit['key'];
            $units[$id] ??= ['unit' => $id, 'key' => $unit['key'], 'value' => '', 'source' => TagKeyLabeler::prettify($unit['key']), 'notes' => 'Tag key: '.$unit['key'], 'targets' => ['de' => '', 'en' => ''], 'category' => '', 'ignore' => false, 'ignores' => ['de' => false, 'en' => false]];
        }

        foreach ($units as $id => $unit) {
            $ignoredByKey = str_starts_with($id, 'value:') && $units['key:'.$unit['key']]['ignore'];
            $units[$id]['ignoredByKey'] = $ignoredByKey && !$unit['ignore'];
            $units[$id]['effectiveIgnore'] = $unit['ignore'] || $ignoredByKey;
        }

        return $units;
    }

    public function page(string $query, bool $untranslated, int $page, string $category = '', string $ignore = 'not_ignored'): array
    {
        $units = $this->units();
        $groups = [];
        foreach ($units as $id => $unit) {
            $groupId = 'key:'.$unit['key'];
            $groups[$groupId] ??= ['key' => $units[$groupId], 'values' => []];
            if (str_starts_with($id, 'value:')) {
                $groups[$groupId]['values'][$id] = $unit;
            }
        }
        ksort($groups, SORT_STRING);
        $pairs = [];
        foreach ($groups as $group) {
            ksort($group['values'], SORT_STRING);
            $hasMatchingValues = false;
            foreach ($group['values'] ?: [null] as $value) {
                $subject = $value ?? $group['key'];
                if (!$this->matchesMetadata($subject, $category, $ignore)) {
                    continue;
                }
                $rows = $value === null ? [$group['key']] : [$group['key'], $value];
                $search = implode("\n", array_map(static fn ($row) => implode("\n", [$row['key'], $row['value'], $row['source'], $row['notes'], ...array_values($row['targets'])]), $rows));
                if ($query !== '' && mb_stripos($search, $query) === false) {
                    continue;
                }
                if ($untranslated && !array_any($rows, static fn ($row) => in_array('', $row['targets'], true))) {
                    continue;
                }
                $pairs[] = $rows;
                $hasMatchingValues = true;
            }
            // Retain matching keys even if none of their values pass the filters.
            if (!$hasMatchingValues && $group['values'] !== [] && $this->matchesMetadata($group['key'], $category, $ignore)) {
                $key = $group['key'];
                $search = implode("\n", [$key['key'], $key['source'], $key['notes'], ...array_values($key['targets'])]);
                if (($query === '' || mb_stripos($search, $query) !== false) && (!$untranslated || in_array('', $key['targets'], true))) {
                    $pairs[] = [$key];
                }
            }
        }
        $total = count($pairs);
        $pages = max(1, (int) ceil($total / 25));
        $page = max(1, min($page, $pages));
        $rows = [];
        foreach (array_slice($pairs, ($page - 1) * 25, 25) as $pair) {
            foreach ($pair as $row) {
                $rows[$row['unit']] = $row;
            }
        }

        return ['rows' => array_values($rows), 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    private function matchesMetadata(array $unit, string $category, string $ignore): bool
    {
        return ($category === '' || $unit['category'] === $category)
            && ($ignore === 'all' || $unit['effectiveIgnore'] === ($ignore === 'ignored'));
    }

    public function save(array $submitted): void
    {
        $known = $this->units();
        $changes = [];
        $seen = [];
        foreach ($submitted as $row) {
            $id = $row['unit'];
            if (!isset($known[$id]) || isset($seen[$id])) {
                throw new \OutOfBoundsException('This translation entry no longer exists or was submitted twice. Reload the page.');
            }
            $seen[$id] = true;
            foreach (['de', 'en'] as $locale) {
                $previous = $row[$locale]['previous'] ?? '';
                $target = $row[$locale]['target'];
                $previousIgnore = $row[$locale]['previousIgnore'] === '1';
                $ignoreChanged = $row['ignore'] !== $previousIgnore;
                if ($target === $previous && !$ignoreChanged) {
                    continue;
                }
                $entry = ['source' => $known[$id]['source'], 'note' => $known[$id]['notes']];
                if ($known[$id]['category'] !== '') {
                    $entry['category'] = $known[$id]['category'];
                }
                if ($target !== $previous) {
                    $entry['target'] = $target;
                    $changes[$locale]['expected'][$id] = $previous;
                }
                if ($ignoreChanged) {
                    $entry['ignore'] = $row['ignore'];
                    $changes[$locale]['expectedIgnores'][$id] = $previousIgnore;
                }
                $changes[$locale]['entries'][$id] = $entry;
            }
        }
        $catalogs = [];
        foreach ($changes as $locale => $change) {
            $catalogs[] = ['path' => $this->catalog->path($locale), 'locale' => $locale, 'expected' => [], ...$change];
        }
        $this->editor->updateCatalogs($catalogs);
    }
}
