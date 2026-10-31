<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** File-backed labels shared by the API and admin; no database overrides. */
final class TagTranslationCatalog
{
    public function __construct(
        #[Autowire('%kernel.project_dir%/tag-translations')]
        private readonly string $directory,
    ) {
    }

    public static function valueId(string $key, string $value): string
    {
        return 'value:'.json_encode([$key, $value], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /** @return array{labels: array<string, string>, values: array<string, string>} */
    public function labels(string $locale): array
    {
        // Most collected targets are empty. Stream the XLIFF instead of building
        // Symfony's full message/metadata tree on every tag request.
        $reader = new \XMLReader();
        $path = $this->path($locale);
        if (!$reader->open($path, null, LIBXML_NONET)) {
            throw new \RuntimeException('Cannot open tag translation catalog: '.$path);
        }
        $result = ['labels' => [], 'values' => []];
        $id = null;
        $previousErrors = libxml_use_internal_errors(true);
        libxml_clear_errors();
        try {
            while ($reader->read()) {
                if ($reader->namespaceURI !== 'urn:oasis:names:tc:xliff:document:2.0') {
                    continue;
                }
                if ($reader->nodeType === \XMLReader::ELEMENT && $reader->localName === 'unit') {
                    $id = $reader->getAttribute('name');
                } elseif ($reader->nodeType === \XMLReader::END_ELEMENT && $reader->localName === 'unit') {
                    $id = null;
                } elseif ($reader->nodeType === \XMLReader::ELEMENT && $reader->localName === 'target' && $id !== null) {
                    $target = $reader->readString();
                    if ($target === '') {
                        continue;
                    }
                    if (str_starts_with($id, 'key:')) {
                        $result['labels'][substr($id, 4)] = $target;
                    } elseif (str_starts_with($id, 'value:')) {
                        $result['values'][substr($id, 6)] = $target;
                    }
                }
            }
            if (libxml_get_errors() !== []) {
                throw new \RuntimeException('Invalid tag translation catalog: '.$path);
            }
        } finally {
            $reader->close();
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrors);
        }

        return $result;
    }

    /** @return \Generator<array<string, mixed>> */
    public function entries(string $locale): \Generator
    {
        $reader = new \XMLReader();
        if (!$reader->open($this->path($locale), null, LIBXML_NONET)) {
            throw new \RuntimeException('Cannot open tag translation catalog.');
        }
        try {
            while ($reader->read()) {
                if ($reader->nodeType !== \XMLReader::ELEMENT || $reader->localName !== 'unit' || $reader->namespaceURI !== 'urn:oasis:names:tc:xliff:document:2.0') {
                    continue;
                }
                $unit = simplexml_load_string($reader->readOuterXml());
                $id = (string) $unit['name'];
                $key = str_starts_with($id, 'key:') ? substr($id, 4) : $id;
                $value = '';
                if (str_starts_with($id, 'value:')) {
                    [$key, $value] = json_decode(substr($id, 6), true, flags: JSON_THROW_ON_ERROR);
                }
                $notes = [];
                foreach ($unit->notes->note ?? [] as $note) {
                    $notes[] = (string) $note;
                }
                $attributes = $unit->attributes('urn:accessapp:tag-references:1');
                yield ['category' => (string) $attributes['category'], 'ignore' => (string) $attributes['ignore'] === 'true', 'unit' => $id, 'key' => $key, 'value' => $value, 'source' => (string) $unit->segment->source, 'target' => (string) $unit->segment->target, 'notes' => implode("\n", $notes)];
            }
        } finally {
            $reader->close();
        }
    }

    public function path(string $locale): string
    {
        if (!\in_array($locale, TagKeyLabeler::SUPPORTED_LOCALES, true)) {
            throw new \InvalidArgumentException('Unsupported tag translation locale.');
        }

        return $this->directory.'/tags.'.$locale.'.xlf';
    }
}
