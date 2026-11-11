<?php

namespace App\Service;

use App\Entity\NodeGroupTag;
use App\Entity\Tag;
use App\Repository\TagRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Rewrites structured street-part tags from the previous direction-less
 * grammar `way.<type>.<index>.<aspect>` into `way.<type>.<direction>.<index>.<aspect>`
 * (see specs/map-editing/street-part-aspects/spec.md R8). The direction of
 * every existing position comes from an explicit mapping the operator
 * supplies; nothing is guessed: unmapped positions and value collisions
 * abort with an actionable report. The conversion is transactional and safe
 * to rerun.
 */
final class AspectDirectionMigration
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TagRepository $tags,
    ) {
    }

    /**
     * @param array<string, string> $mapping `"<type>.<index>"` => direction
     */
    public function convert(array $mapping): void
    {
        $this->validateMapping($mapping);

        $oldEntries = [];
        foreach ($this->em->getRepository(NodeGroupTag::class)->findAll() as $entry) {
            if (StreetPartAspects::parseLegacy($entry->getTag()->getKey()) !== null) {
                $oldEntries[] = $entry;
            }
        }

        // Preflight every rewrite before writing anything.
        $missing = [];
        foreach ($oldEntries as $entry) {
            ['type' => $type, 'index' => $index] = StreetPartAspects::parseLegacy($entry->getTag()->getKey());
            $direction = $mapping[$type.'.'.$index] ?? null;
            if ($direction === null) {
                $missing[$type.'.'.$index] = true;
            }
        }
        if ($missing !== []) {
            throw new \RuntimeException('No direction mapped for legacy position(s): '.implode(', ', array_keys($missing)).'. Add them to the mapping file and retry.');
        }

        $this->em->wrapInTransaction(function () use ($oldEntries, $mapping): void {
            foreach ($oldEntries as $entry) {
                $tag = $entry->getTag();
                ['type' => $type, 'index' => $index, 'aspect' => $aspect] = StreetPartAspects::parseLegacy($tag->getKey());
                $direction = $mapping[$type.'.'.$index];
                $newKey = 'way.'.$type.'.'.$direction.'.'.$index.'.'.$aspect;

                // Any existing assignment under the new key decides whether
                // this is an idempotent rerun or a value collision: a
                // differing value must not produce competing rewritten rows.
                $existing = null;
                foreach ($entry->getGroup()->getGroupTags() as $other) {
                    if ($other === $entry || $other->getTag()->getKey() !== $newKey) {
                        continue;
                    }
                    if ($other->getTag()->getValue() !== $tag->getValue()) {
                        throw new \RuntimeException('Existing tag collision for '.$newKey.' on part #'.$entry->getGroup()->getId().'; resolve before retrying.');
                    }
                    if ($other->getCreatedByUser() === $entry->getCreatedByUser()) {
                        if ($other->isApproved() !== $entry->isApproved()) {
                            throw new \RuntimeException('Existing tag collision for '.$newKey.' on part #'.$entry->getGroup()->getId().'; resolve before retrying.');
                        }
                        $existing = $other;
                    }
                }
                if ($existing === null) {
                    $tagExists = $this->em->getRepository(Tag::class)->findOneBy(['key' => $newKey, 'value' => $tag->getValue()]) !== null;
                    $newTag = $this->tags->findOrCreate($newKey, $tag->getValue());
                    if (!$tagExists) {
                        // Only a freshly created tag inherits the legacy tag's
                        // translation proposals; an existing one keeps its own.
                        $newTag->setTranslations($tag->getTranslations());
                    }
                    $assignment = new NodeGroupTag($entry->getGroup(), $newTag, $entry->getCreatedByUser());
                    $assignment->setApproved($entry->isApproved());
                    $this->em->persist($assignment);
                }

                $entry->getGroup()->getGroupTags()->removeElement($entry);
                $this->em->remove($entry);
            }
        });

        $this->verify();
    }

    private function verify(): void
    {
        $this->em->clear();
        foreach ($this->em->getRepository(NodeGroupTag::class)->findAll() as $entry) {
            if (StreetPartAspects::parseLegacy($entry->getTag()->getKey()) !== null) {
                throw new \RuntimeException('Direction migration left a legacy key behind: '.$entry->getTag()->getKey());
            }
        }
    }

    /** @param array<string, string> $mapping */
    private function validateMapping(array $mapping): void
    {
        foreach ($mapping as $position => $direction) {
            $parts = \is_string($position) ? explode('.', $position) : [];
            if (
                count($parts) !== 2
                || trim($parts[0]) === ''
                || !preg_match('/^[1-9][0-9]*$/D', $parts[1])
                || !\in_array($direction, StreetPartAspects::DIRECTIONS, true)
            ) {
                throw new \RuntimeException('Malformed mapping entry "'.$position.'" => "'.$direction.'"');
            }
        }
    }
}
