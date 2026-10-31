<?php

namespace App\Service;

use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Translation\Loader\XliffFileLoader;

/** Synchronize or edit targets without rebuilding existing units and metadata. */
final class TagTranslationCatalogEditor
{
    private const NS = 'urn:oasis:names:tc:xliff:document:2.0';
    private const REFERENCE_NS = 'urn:accessapp:tag-references:1';

    public function __construct(private readonly LockFactory $lockFactory)
    {
    }

    public function updateTarget(string $path, string $locale, string $unit, string $target, string $previous): void
    {
        $this->merge($path, $locale, [$unit => ['source' => '', 'note' => '', 'target' => $target]], [$unit => $previous]);
    }

    /** Validate and stage the whole bilingual form before publishing either file.
     * @param list<array{path: string, locale: string, entries: array, expected: array, expectedIgnores?: array<string, bool>}> $catalogs
     */
    public function updateCatalogs(array $catalogs): void
    {
        usort($catalogs, static fn ($a, $b) => strcmp($a['path'], $b['path']));
        $locks = [];
        $staged = [];
        $originals = [];
        $published = [];
        try {
            foreach ($catalogs as $catalog) {
                $path = $catalog['path'];
                $lock = $this->lockFactory->createLock('tag-catalog:'.hash('sha256', realpath($path) ?: $path));
                if (!$lock->acquire(true)) {
                    throw new \RuntimeException('Could not lock the translation catalog.');
                }
                $locks[] = $lock;
            }
            foreach ($catalogs as $catalog) {
                $path = $catalog['path'];
                $originals[$path] = file_get_contents($path);
                $temporary = tempnam(\dirname($path), '.tag-batch-');
                if ($temporary === false) {
                    throw new \RuntimeException('Could not stage translation catalogs.');
                }
                $staged[$path] = $temporary;
                if (!copy($path, $temporary)) {
                    throw new \RuntimeException('Could not stage translation catalogs.');
                }
                chmod($temporary, fileperms($path) & 0777);
                $this->mergeLocked($temporary, $catalog['locale'], $catalog['entries'], $catalog['expected'], true, false, $catalog['expectedIgnores'] ?? []);
            }
            foreach ($staged as $path => $temporary) {
                if (!rename($temporary, $path)) {
                    throw new \RuntimeException('Could not publish translation catalogs.');
                }
                $published[] = $path;
            }
        } catch (\Throwable $exception) {
            foreach ($published as $path) {
                $restore = tempnam(\dirname($path), '.tag-restore-');
                if ($restore === false) {
                    throw new \RuntimeException('Could not restore the translation catalog.', previous: $exception);
                }
                try {
                    chmod($restore, fileperms($path) & 0777);
                    if (file_put_contents($restore, $originals[$path]) === false || !rename($restore, $path)) {
                        throw new \RuntimeException('Could not restore the translation catalog.', previous: $exception);
                    }
                } finally {
                    if (is_file($restore)) {
                        unlink($restore);
                    }
                }
            }
            throw $exception;
        } finally {
            foreach ($staged as $temporary) {
                if (is_file($temporary)) {
                    unlink($temporary);
                }
            }
            foreach (array_reverse($locks) as $lock) {
                $lock->release();
            }
        }
    }

    /**
     * @param array<string, array{source: string, note: string, target?: string, category?: string, ignore?: bool}> $entries
     * @param array<string, string> $expectedTargets
     * @param bool $linkReferences Rebuild reciprocal key/value unit links during generation.
     */
    public function merge(string $path, string $locale, array $entries, array $expectedTargets = [], bool $allowNewTargets = false, bool $linkReferences = false): int
    {
        $lock = $this->lockFactory->createLock('tag-catalog:'.hash('sha256', realpath($path) ?: $path));
        if (!$lock->acquire(true)) {
            throw new \RuntimeException('Could not lock the translation catalog.');
        }
        try {
            return $this->mergeLocked($path, $locale, $entries, $expectedTargets, $allowNewTargets, $linkReferences);
        } finally {
            $lock->release();
        }
    }

    /**
     * @param array<string, array{source: string, note: string, target?: string, category?: string, ignore?: bool}> $entries
     * @param array<string, string> $expectedTargets
     * @param array<string, bool> $expectedIgnores
     */
    private function mergeLocked(string $path, string $locale, array $entries, array $expectedTargets, bool $allowNewTargets, bool $linkReferences, array $expectedIgnores = []): int
    {
        // Validate before editing; never silently replace a malformed catalog.
        (new XliffFileLoader())->load($path, $locale, 'tags');
        $document = new \DOMDocument();
        $document->load($path, LIBXML_NONET);
        $units = [];
        foreach ($document->getElementsByTagNameNS(self::NS, 'unit') as $unit) {
            $units[$unit->getAttribute('name')] = $unit;
        }
        foreach ($expectedTargets as $id => $previous) {
            if (!isset($units[$id])) {
                if ($allowNewTargets && $previous === '') {
                    continue;
                }
                throw new \OutOfBoundsException('This translation entry no longer exists. Reload the page.');
            }
            $segments = $units[$id]->getElementsByTagNameNS(self::NS, 'segment');
            $target = $units[$id]->getElementsByTagNameNS(self::NS, 'target')->item(0);
            if ($segments->length !== 1 || ($target !== null && $target->childElementCount > 0)) {
                throw new \InvalidArgumentException('This entry contains structured translation content and must be edited in an XLIFF editor.');
            }
            if (($target?->textContent ?? '') !== $previous && (!$allowNewTargets || ($target?->textContent ?? '') !== ($entries[$id]['target'] ?? null))) {
                throw new \DomainException('This translation changed since you opened the page. Reload to see the latest text before saving again.');
            }
        }
        foreach ($expectedIgnores as $id => $previous) {
            $current = isset($units[$id]) && $units[$id]->getAttributeNS(self::REFERENCE_NS, 'ignore') === 'true';
            if ($current !== $previous) {
                throw new \DomainException('The ignore flag changed since you opened the page. Reload before saving again.');
            }
        }
        if ($linkReferences) {
            // A value always needs a key unit to reference, including values
            // retained in the catalog after they disappear from the inputs.
            foreach (array_unique([...array_keys($units), ...array_keys($entries)]) as $id) {
                if (str_starts_with($id, 'value:')) {
                    [$key] = json_decode(substr($id, 6), true, flags: JSON_THROW_ON_ERROR);
                    if (!isset($units['key:'.$key]) && !isset($entries['key:'.$key])) {
                        $entries['key:'.$key] = ['source' => TagKeyLabeler::prettify($key), 'note' => 'Tag key: '.$key];
                    }
                }
            }
        }
        $file = $document->getElementsByTagNameNS(self::NS, 'file')->item(0);
        $added = 0;
        $metadataChanged = false;
        foreach ($entries as $id => $entry) {
            if (isset($units[$id])) {
                // Explicit translation edits and legacy imports may replace a target.
                if (isset($entry['target'])) {
                    $segment = $units[$id]->getElementsByTagNameNS(self::NS, 'segment')->item(0);
                    $target = $segment->getElementsByTagNameNS(self::NS, 'target')->item(0);
                    if ($target === null) {
                        $target = $segment->appendChild($document->createElementNS(self::NS, 'target'));
                    }
                    $target->textContent = $entry['target'];
                    $segment->setAttribute('state', $entry['target'] === '' ? 'initial' : 'translated');
                }
                if (isset($entry['ignore'])) {
                    $metadataChanged = $this->setIgnore($document, $units[$id], $entry['ignore']) || $metadataChanged;
                }
                if (isset($entry['category'])) {
                    $metadataChanged = $this->setCategory($document, $units[$id], $entry['category']) || $metadataChanged;
                }
                continue;
            }
            $unit = $document->createElementNS(self::NS, 'unit');
            $unit->setAttribute('id', 't_'.hash('sha256', $id));
            $unit->setAttribute('name', $id);
            $this->setIgnore($document, $unit, $entry['ignore'] ?? false);
            if (isset($entry['category'])) {
                $this->setCategory($document, $unit, $entry['category']);
            }
            if ($entry['note'] !== '') {
                $notes = $unit->appendChild($document->createElementNS(self::NS, 'notes'));
                $note = $notes->appendChild($document->createElementNS(self::NS, 'note'));
                $note->textContent = $entry['note'];
            }
            $segment = $unit->appendChild($document->createElementNS(self::NS, 'segment'));
            $segment->setAttribute('state', ($entry['target'] ?? '') !== '' ? 'translated' : 'initial');
            $source = $segment->appendChild($document->createElementNS(self::NS, 'source'));
            $source->textContent = $entry['source'];
            // Empty targets use the application's fallback until translated.
            $target = $segment->appendChild($document->createElementNS(self::NS, 'target'));
            if (isset($entry['target'])) {
                $target->textContent = $entry['target'];
            }
            $this->indent($document, $unit, 2);
            if ($file->lastChild instanceof \DOMText && trim($file->lastChild->textContent) === '') {
                $file->lastChild->textContent = "\n    ";
            } else {
                $file->appendChild($document->createTextNode("\n    "));
            }
            $file->appendChild($unit);
            $file->appendChild($document->createTextNode("\n  "));
            $units[$id] = $unit;
            ++$added;
        }
        if ($linkReferences) {
            foreach ($units as $unit) {
                if (!$unit->hasAttributeNS(self::REFERENCE_NS, 'ignore')) {
                    $metadataChanged = $this->setIgnore($document, $unit, false) || $metadataChanged;
                }
            }
            $metadataChanged = $this->removeGeneratedValueNotes($units) || $metadataChanged;
        }
        $referencesChanged = $linkReferences && $this->linkReferences($document, $units);
        if ($added === 0 && !$metadataChanged && !$referencesChanged && !array_any($entries, static fn (array $entry): bool => isset($entry['target']))) {
            return 0;
        }
        $xml = $document->saveXML();
        (new XliffFileLoader())->load($xml, $locale, 'tags');
        // Replace only a complete, valid document.
        $temporary = tempnam(\dirname($path), '.tags-');
        if ($temporary === false) {
            throw new \RuntimeException('Could not create temporary tag catalog.');
        }
        try {
            chmod($temporary, fileperms($path) & 0777);
            if (file_put_contents($temporary, $xml) === false || !rename($temporary, $path)) {
                throw new \RuntimeException('Could not write tag translation catalog.');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }

        return $added;
    }

    private function setIgnore(\DOMDocument $document, \DOMElement $unit, bool $ignore): bool
    {
        $value = $ignore ? 'true' : 'false';
        if ($unit->getAttributeNS(self::REFERENCE_NS, 'ignore') === $value) {
            return false;
        }
        $document->documentElement->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:tag', self::REFERENCE_NS);
        $unit->setAttributeNS(self::REFERENCE_NS, 'tag:ignore', $value);

        return true;
    }

    private function setCategory(\DOMDocument $document, \DOMElement $unit, string $category): bool
    {
        if (!in_array($category, ['osm', 'custom'], true)) {
            throw new \InvalidArgumentException('Unsupported tag translation category.');
        }
        $current = $unit->getAttributeNS(self::REFERENCE_NS, 'category');
        // Database-only syncs must not reclassify an already identified OSM tag.
        if ($current === 'osm' || $current === $category) {
            return false;
        }
        $document->documentElement->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:tag', self::REFERENCE_NS);
        $unit->setAttributeNS(self::REFERENCE_NS, 'tag:category', $category);

        return true;
    }

    /** Remove only redundant generated value context, preserving translator notes.
     * @param array<string, \DOMElement> $units
     */
    private function removeGeneratedValueNotes(array $units): bool
    {
        $changed = false;
        foreach ($units as $id => $unit) {
            if (!str_starts_with($id, 'value:')) {
                continue;
            }
            $notes = $unit->getElementsByTagNameNS(self::NS, 'notes')->item(0);
            if ($notes === null) {
                continue;
            }
            foreach (iterator_to_array($notes->getElementsByTagNameNS(self::NS, 'note')) as $note) {
                if (str_starts_with(trim($note->textContent), 'Tag value:')) {
                    $this->removeElement($note);
                    $changed = true;
                }
            }
            if ($notes->getElementsByTagNameNS(self::NS, 'note')->length === 0) {
                $this->removeElement($notes);
                $changed = true;
            }
        }

        return $changed;
    }

    private function removeElement(\DOMElement $element): void
    {
        $previous = $element->previousSibling;
        if ($previous instanceof \DOMText && trim($previous->textContent) === '') {
            $previous->parentNode->removeChild($previous);
        }
        $element->parentNode->removeChild($element);
    }

    /** @param array<string, \DOMElement> $units */
    private function linkReferences(\DOMDocument $document, array $units): bool
    {
        $keyValues = [];
        $valueKeys = [];
        foreach ($units as $name => $unit) {
            if (!str_starts_with($name, 'value:')) {
                continue;
            }
            [$key] = json_decode(substr($name, 6), true, flags: JSON_THROW_ON_ERROR);
            $keyUnit = $units['key:'.$key];
            $keyValues['key:'.$key][] = $unit->getAttribute('id');
            $valueKeys[$name] = $keyUnit->getAttribute('id');
        }
        $changed = false;
        if ($valueKeys !== [] && !$document->documentElement->hasAttributeNS('http://www.w3.org/2000/xmlns/', 'tag')) {
            $document->documentElement->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:tag', self::REFERENCE_NS);
            $changed = true;
        }
        foreach ($units as $name => $unit) {
            $values = $keyValues[$name] ?? [];
            sort($values, SORT_STRING);
            foreach (['keyRef' => $valueKeys[$name] ?? '', 'valueRefs' => implode(' ', $values)] as $attribute => $references) {
                if ($unit->getAttributeNS(self::REFERENCE_NS, $attribute) === $references) {
                    continue;
                }
                if ($references === '') {
                    $unit->removeAttributeNS(self::REFERENCE_NS, $attribute);
                } else {
                    $unit->setAttributeNS(self::REFERENCE_NS, 'tag:'.$attribute, $references);
                }
                $changed = true;
            }
        }

        return $changed;
    }

    private function indent(\DOMDocument $document, \DOMElement $element, int $depth): void
    {
        $children = iterator_to_array($element->childNodes);
        foreach ($children as $child) {
            if ($child instanceof \DOMElement) {
                $element->insertBefore($document->createTextNode("\n".str_repeat('  ', $depth + 1)), $child);
                $this->indent($document, $child, $depth + 1);
            }
        }
        if (array_any($children, static fn ($child): bool => $child instanceof \DOMElement)) {
            $element->appendChild($document->createTextNode("\n".str_repeat('  ', $depth)));
        }
    }
}
