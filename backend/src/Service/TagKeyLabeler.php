<?php

namespace App\Service;

use App\Repository\TagKeyLabelRepository;
use Symfony\Component\Translation\LocaleSwitcher;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Resolves a tag key (e.g. "fire_hydrant:type") to a display label. The
 * lookup chain is: an admin-managed label from the database, then the
 * tag_keys translation catalogue (translations/tag_keys.*.xlf, generated
 * from the OSM key import), then the prettified key itself — so an unknown
 * key is always readable. Used by EasyAdmin and by the /api/i18n/tag-keys
 * endpoint that the Vue app consumes.
 */
final class TagKeyLabeler
{
    /** The locales the frontend offers; also the endpoint's allowed values. */
    public const SUPPORTED_LOCALES = ['en', 'de'];

    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly TagKeyLabelRepository $labels,
        #[Autowire(service: 'translation.locale_switcher')]
        private readonly LocaleSwitcher $localeSwitcher,
    ) {
    }

    public function label(string $key, ?string $locale = null): string
    {
        $locale ??= $this->localeSwitcher->getLocale();

        $label = $this->labels->findForLocale($locale)[$key] ?? null;
        if ($label !== null && $label !== '') {
            return $label;
        }

        // Keys are the message ids, so a missing catalogue entry comes back
        // unchanged; that case falls through to the prettified key.
        $translated = $this->translator->trans($key, domain: 'tag_keys', locale: $locale);
        if ($translated !== $key && $translated !== '') {
            return $translated;
        }

        return self::prettify($key);
    }

    /**
     * The full resolved map for one locale (catalogue plus database
     * overrides), served to the frontend; keys absent here fall back to the
     * prettified key client-side.
     *
     * @return array<string, string>
     */
    public function labelsForLocale(string $locale): array
    {
        $catalog = $this->translator->getCatalogue($locale)->all('tag_keys');

        return array_merge($catalog, $this->labels->findForLocale($locale));
    }

    /**
     * Last-resort display version of a raw key: underscores become spaces
     * ("tactile_paving" → "tactile paving"). Keep in sync with the frontend
     * helper in src/stores/tagKeyLabels.ts.
     */
    public static function prettify(string $key): string
    {
        return str_replace('_', ' ', $key);
    }
}
