<?php

namespace App\Service;

use App\Entity\NodeGroup;
use App\Entity\NodeGroupTag;
use App\Entity\Tag;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Structured street-part observations always arrive proposing a NEW element:
 * the composer assigns the next free index and locks renumbering once an
 * element has approved tags. Mapping lets the reviewer publish a pending
 * observation under an EXISTING element's identity instead, so approval
 * becomes an explicit update-vs-add decision (see
 * specs/map-editing/aspect-observation-mapping/spec.md). The decision is
 * per aspect tag and part of the approve action only; declined observations
 * are unaffected.
 */
final class AspectObservationApproval
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Mapping options for a pending structured observation: the existing
     * approved elements of the same type. The payload carries the element
     * position (direction and index) and the approved value of the
     * observation's aspect at review time; approve compares it against the
     * current state (staleness).
     *
     * @return list<array{payload: string, label: string}>
     */
    public function targetOptions(NodeGroup $group, Tag $proposedTag): array
    {
        $proposed = StreetPartAspects::parse($proposedTag->getKey());
        if ($proposed === null) {
            return [];
        }

        $options = [];
        foreach ($this->approvedElements($group) as $position => $element) {
            if ($element['type'] !== $proposed['type']) {
                continue;
            }
            $options[] = [
                // PHP casts numeric-string array keys to int; keep the
                // index a canonical string in the payload.
                'payload' => json_encode(
                    ['direction' => $element['direction'], 'index' => (string) $element['index'], 'aspectValue' => $element['aspects'][$proposed['aspect']] ?? null],
                    JSON_THROW_ON_ERROR
                ),
                'label' => $element['type'].' '.$element['direction'].' '.$element['index'].' ('.$this->aspectSummary($element['aspects']).')',
            ];
        }

        return $options;
    }

    /**
     * The approved cross-section of a street part, for review context.
     *
     * @return list<array{type: string, direction: string, index: string, aspects: array<string, string>}>
     */
    public function approvedCrossSection(NodeGroup $group): array
    {
        $elements = [];
        foreach ($this->approvedElements($group) as $element) {
            $elements[] = ['type' => $element['type'], 'direction' => $element['direction'], 'index' => (string) $element['index'], 'aspects' => $element['aspects']];
        }

        return $elements;
    }

    /**
     * Decodes a mapping payload posted from the review screen. Returns the
     * target element position and the approved value of the mapped aspect
     * at review time (null when the target element has none yet).
     *
     * @return array{direction: string, index: string, aspectValue: ?string}
     */
    public function decodeTarget(string $payload): array
    {
        try {
            $decoded = json_decode($payload, true, 2, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new BadRequestHttpException('Invalid mapping target: '.$payload);
        }
        if (
            !\is_array($decoded)
            || !\in_array($decoded['direction'] ?? null, StreetPartAspects::DIRECTIONS, true)
            || !(\is_string($decoded['index'] ?? null) || \is_int($decoded['index'] ?? null))
            || !preg_match('/^[1-9][0-9]*$/D', (string) ($decoded['index'] ?? null))
            || !(\is_string($decoded['aspectValue'] ?? null) || $decoded['aspectValue'] === null)
        ) {
            throw new BadRequestHttpException('Invalid mapping target: '.$payload);
        }

        return ['direction' => $decoded['direction'], 'index' => (string) $decoded['index'], 'aspectValue' => $decoded['aspectValue']];
    }

    /**
     * The currently approved value of an exact key on this part, or null
     * when the aspect has no approved value there.
     */
    public function approvedAspectValue(NodeGroup $group, string $key): ?string
    {
        foreach ($group->getGroupTags() as $entry) {
            if ($entry->isApproved() && $entry->getTag()->getKey() === $key) {
                return $entry->getTag()->getValue();
            }
        }

        return null;
    }

    /**
     * The shared tag to publish under. Existing matches are reused; a new
     * one is returned unpersisted and seeded with the observation's
     * proposals so approval publishes reviewed translations under the
     * effective identity, never the proposed one.
     */
    public function effectiveTag(Tag $proposedTag, string $effectiveKey, string $value): Tag
    {
        $existing = $this->entityManager->getRepository(Tag::class)->findOneBy(['key' => $effectiveKey, 'value' => $value]);
        if ($existing !== null) {
            return $existing;
        }

        $tag = new Tag();
        $tag->setKey($effectiveKey);
        $tag->setValue($value);
        $tag->setTranslations($proposedTag->getTranslations());

        return $tag;
    }

    /**
     * Publishes the mapped approval: every contributor of the proposed
     * value gets an approved assignment of the effective tag with their own
     * attribution; pending proposals of the proposed and effective keys and
     * the formerly approved effective value are superseded — the replaced
     * value is removed without history.
     */
    public function apply(NodeGroupTag $entry, Tag $effectiveTag): void
    {
        $group = $entry->getGroup();
        $proposedTag = $entry->getTag();
        $proposedKey = $proposedTag->getKey();
        $effectiveKey = $effectiveTag->getKey();

        // The contributor set mirrors a plain approval: every user's pending
        // assignment of the proposed value here is approved (under the
        // effective identity).
        $creators = [];
        foreach ($group->getGroupTags() as $other) {
            if (!$other->isApproved() && $other->getTag() === $proposedTag) {
                $creators[$other->getCreatedByUser()->getId()] = $other->getCreatedByUser();
            }
        }

        foreach ($creators as $creator) {
            $assignment = null;
            foreach ($group->getGroupTags() as $other) {
                if ($other->getTag() === $effectiveTag && $other->getCreatedByUser() === $creator) {
                    $assignment = $other;
                    break;
                }
            }
            if ($assignment === null) {
                $assignment = new NodeGroupTag($group, $effectiveTag, $creator);
                $assignment->setApproved(true);
                $group->getGroupTags()->add($assignment);
                $this->entityManager->persist($effectiveTag);
                $this->entityManager->persist($assignment);
            } else {
                $assignment->setApproved(true);
            }
        }

        // Supersede: pending proposals of the proposed key (any value), and
        // every assignment of the effective key with a different value —
        // approved ones are replaced, pending ones superseded. Same-tag
        // rows were approved above and stay.
        foreach ($group->getGroupTags()->toArray() as $other) {
            $key = $other->getTag()->getKey();
            if ($other->getTag() === $effectiveTag
                || ($key !== $proposedKey && $key !== $effectiveKey)) {
                continue;
            }
            $group->getGroupTags()->removeElement($other);
            $this->entityManager->remove($other);
        }
    }

    /**
     * @return array<string, array{type: string, direction: string, index: string, aspects: array<string, string>}>
     */
    private function approvedElements(NodeGroup $group): array
    {
        $elements = [];
        foreach ($group->getGroupTags() as $entry) {
            if (!$entry->isApproved()) {
                continue;
            }
            $parsed = StreetPartAspects::parse($entry->getTag()->getKey());
            if ($parsed === null) {
                continue;
            }
            $position = $parsed['direction'].'.'.$parsed['index'];
            $elements[$position]['type'] ??= $parsed['type'];
            $elements[$position]['direction'] ??= $parsed['direction'];
            $elements[$position]['index'] ??= $parsed['index'];
            $elements[$position]['aspects'][$parsed['aspect']] = $entry->getTag()->getValue();
        }
        // Canonical order: the fixed direction order, then the numeric index within it.
        $directionOrder = array_flip(StreetPartAspects::DIRECTIONS);
        uasort($elements, static function (array $a, array $b) use ($directionOrder): int {
            return $directionOrder[$a['direction']] <=> $directionOrder[$b['direction']]
                ?: (\strlen($a['index']) <=> \strlen($b['index']) ?: strcmp($a['index'], $b['index']));
        });

        return $elements;
    }

    /** @param array<string, string> $aspects */
    private function aspectSummary(array $aspects): string
    {
        return implode(', ', array_map(
            static fn (string $aspect, string $value): string => $aspect.'='.$value,
            array_keys($aspects),
            $aspects
        ));
    }
}
