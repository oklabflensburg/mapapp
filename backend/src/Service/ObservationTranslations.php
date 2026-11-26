<?php

namespace App\Service;

use App\Api\SaveNodeGroupRequest;
use App\Api\SaveUserNodeRequest;
use App\Entity\Node;
use App\Entity\NodeGroup;
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
                if ($this->apply($tag, $translation, $request->translationLocale)) {
                    $nodeTag->setApproved(false);
                }
                break;
            }
        }
    }

    /**
     * Same contract for observation relations: proposals attach to the one
     * tag the relation carries, and proposing a translation unapproves the
     * editor's own row again.
     */
    public function saveGroup(SaveNodeGroupRequest $request, NodeGroup $group, User $user): void
    {
        $entry = $request->tags[0] ?? null;
        foreach ($request->translations as $translation) {
            if ($entry === null || $translation['key'] !== $entry['key'] || $translation['value'] !== $entry['value']) {
                throw new UnprocessableEntityHttpException('Translations must belong to a submitted observation.');
            }
            foreach ($group->getGroupTags() as $groupTag) {
                $tag = $groupTag->getTag();
                if ($groupTag->getCreatedByUser() !== $user || $tag->getKey() !== $translation['key'] || $tag->getValue() !== $translation['value']) {
                    continue;
                }
                if ($this->apply($tag, $translation, $request->translationLocale)) {
                    $groupTag->setApproved(false);
                }
                break;
            }
        }
    }

    /**
     * @param array<string, string|null> $translation
     */
    private function apply(Tag $tag, array $translation, string $locale): bool
    {
        $translations = $tag->getTranslations();
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
        }

        return $changed;
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
