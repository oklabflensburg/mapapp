<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Translation\LocaleSwitcher;
use Symfony\Contracts\Service\ResetInterface;

/** Resolves both OSM and user-created tags from the same XLIFF catalogs. */
final class TagKeyLabeler implements ResetInterface
{
    public const SUPPORTED_LOCALES = ['en', 'de'];

    /** @var array<string, array{labels: array<string, string>, values: array<string, string>}> */
    private array $catalogs = [];

    public function __construct(
        private readonly TagTranslationCatalog $catalog,
        #[Autowire(service: 'translation.locale_switcher')]
        private readonly LocaleSwitcher $localeSwitcher,
    ) {
    }

    public function label(string $key, ?string $locale = null): string
    {
        return $this->translationsForLocale($locale ?? $this->localeSwitcher->getLocale())['labels'][$key] ?? self::prettify($key);
    }

    public function valueLabel(string $key, string $value, ?string $locale = null): string
    {
        $id = substr(TagTranslationCatalog::valueId($key, $value), 6);

        // Unknown values, including names, URLs and free text, stay verbatim.
        return $this->translationsForLocale($locale ?? $this->localeSwitcher->getLocale())['values'][$id] ?? $value;
    }

    /** @return array<string, string> */
    public function labelsForLocale(string $locale): array
    {
        return $this->translationsForLocale($locale)['labels'];
    }

    /** @return array{labels: array<string, string>, values: array<string, string>} */
    public function translationsForLocale(string $locale): array
    {
        return $this->catalogs[$locale] ??= $this->catalog->labels($locale);
    }

    /** Symfony workers discard request-local maps before serving another request. */
    public function reset(): void
    {
        $this->catalogs = [];
    }

    public static function prettify(string $key): string
    {
        return str_replace('_', ' ', $key);
    }
}
