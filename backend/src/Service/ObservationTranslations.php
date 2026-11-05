<?php

namespace App\Service;

use App\Api\SaveUserNodeRequest;
use App\Entity\Node;
use App\Entity\Tag;
use App\Entity\User;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/** Proposed translations live on tags; only approval publishes catalog targets. */
final class ObservationTranslations
{
    public function __construct(
        private readonly TagTranslationCatalog $catalog,
        private readonly TagTranslationCatalogEditor $editor,
    ) {
    }

    public function save(SaveUserNodeRequest $request, Node $node, User $user): void
    {
        $submitted = SaveUserNodeRequest::tagEntryCounts($request->tags);
        foreach ($request->translations as $translation) {
            if (($submitted[$translation['key']."\0".$translation['value']] ?? 0) < 1) {
                throw new UnprocessableEntityHttpException('Translations must belong to a submitted observation.');
            }
            foreach ($node->getNodeTags() as $nodeTag) {
                $tag = $nodeTag->getTag();
                if ($nodeTag->getCreatedByUser() !== $user || $tag->getKey() !== $translation['key'] || $tag->getValue() !== $translation['value']) {
                    continue;
                }
                $translations = $tag->getTranslations();
                $locale = $request->translationLocale;
                $changed = false;
                foreach (['keyTranslation', 'valueTranslation'] as $field) {
                    if (!isset($translation[$field])) {
                        continue;
                    }
                    $current = $translations[$locale][$field] ?? null;
                    $previous = $translation['previous'.ucfirst($field)] ?? '';
                    if ($current !== null && $current !== $previous && $current !== $translation[$field]) {
                        throw new ConflictHttpException('The proposed translation changed. Reload before saving again.');
                    }
                    $changed = $changed || $current !== $translation[$field];
                    $translations[$locale][$field] = $translation[$field];
                }
                if ($changed) {
                    $tag->setTranslations($translations);
                    $nodeTag->setApproved(false);
                }
                break;
            }
        }
    }

    /** @param array<string, array<string, string>> $expectedTargets */
    public function publish(Tag $tag, array $expectedTargets = []): void
    {
        foreach ($tag->getTranslations() as $locale => $translations) {
            $entries = [];
            $expected = [];
            foreach ([
                ['keyTranslation', 'key:'.$tag->getKey(), TagKeyLabeler::prettify($tag->getKey()), 'Tag key: '.$tag->getKey()],
                ['valueTranslation', TagTranslationCatalog::valueId($tag->getKey(), $tag->getValue()), $tag->getValue(), 'Tag value: '.$tag],
            ] as [$field, $id, $source, $note]) {
                if (!array_key_exists($field, $translations)) {
                    continue;
                }
                $entries[$id] = ['source' => $source, 'note' => $note, 'target' => $translations[$field]];
                if (isset($expectedTargets[$locale][$field])) {
                    $expected[$id] = $expectedTargets[$locale][$field];
                }
            }
            if ($entries !== []) {
                $this->editor->merge($this->catalog->path($locale), $locale, $entries, $expected, allowNewTargets: true);
            }
        }
    }
}
