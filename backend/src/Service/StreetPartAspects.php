<?php

namespace App\Service;

use App\Entity\NodeGroup;
use App\Entity\NodeGroupTag;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Cross-sections are a view of ordinary, moderated relation tags. Under the
 * observation-relations model every observation is its own single-tag
 * relation (see specs/map-editing/observation-relations/spec.md), so a
 * structured key identifies one aspect of one element side: the former
 * index segment is gone.
 */
final class StreetPartAspects
{
    /**
     * Element side tokens across the cross-section, in canonical order
     * (relative to the relation's direction of travel, like OSM's
     * `:left`/`:right` suffixes; `center` is the carriageway).
     */
    public const SIDES = ['left', 'right', 'center'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly StreetPartArea $area,
        private readonly AspectCatalog $definitions,
    ) {
    }

    public function catalog(): array
    {
        return $this->definitions->read()['types'];
    }

    /** @return array{type: string, side: string, aspect: string}|null */
    public static function parse(string $key): ?array
    {
        if (!preg_match('/\A([^.\\s]+)\.(left|right|center)\.([^.\\s]+)\z/u', $key, $match) || mb_strlen($key) > 255) {
            return null;
        }

        return ['type' => $match[1], 'side' => $match[2], 'aspect' => $match[3]];
    }

    /**
     * Whether a key declares a cross-section position: it parses as a
     * structured key, or it carries a side token as the second dot segment
     * of a three-or-more-segment key (including the former indexed
     * `<type>.<side>.<index>.<aspect>` shape), so a typo near the
     * grammar (an extra segment, a stray side token) stays a validation
     * error instead of silently becoming an ordinary tag. Keys that parse
     * are the valid structured keys; reserved ones that do not are
     * malformed and rejected on save.
     */
    public static function isStructuredKey(string $key): bool
    {
        if (self::parse($key) !== null) {
            return true;
        }
        $segments = explode('.', $key);

        return \count($segments) >= 3 && \in_array($segments[1], self::SIDES, true);
    }

    /**
     * Validates the single tag a relation save carries against the catalog.
     * An exact echo of the relation's own observation (key and value) skips
     * validation; anything else must be a known type/aspect with an allowed
     * value.
     *
     * @param list<array{key: string, value: string}> $tags
     */
    public function validate(array $tags, NodeGroup $group, User $user, bool $admin): void
    {
        $tag = $tags[0] ?? null;
        if ($tag === null || !self::isStructuredKey($tag['key'])) {
            return;
        }
        $echo = false;
        foreach ($group->getGroupTags() as $entry) {
            if ($entry->getCreatedByUser() === $user && $entry->getTag()->getKey() === $tag['key'] && $entry->getTag()->getValue() === $tag['value']) {
                $echo = true;
            }
        }
        if ($echo) {
            return;
        }
        $parsed = self::parse($tag['key']);
        if ($parsed === null) {
            throw new UnprocessableEntityHttpException('Malformed structured tag key.');
        }
        $definitions = [];
        foreach ($this->catalog() as $type) {
            foreach ($type['attributes'] as $attribute) {
                $definitions[$type['name']][$attribute['key']] = $attribute['values'];
            }
        }
        if (!isset($definitions[$parsed['type']][$parsed['aspect']])
            || !\in_array($tag['value'], $definitions[$parsed['type']][$parsed['aspect']], true)) {
            throw new UnprocessableEntityHttpException('Unknown cross-section type, aspect or value.');
        }
    }

    public function preferences(): array
    {
        return array_column($this->definitions->read()['preferences'], 'label', 'key');
    }

    public function routingAreas(array $keys): array
    {
        $preferences = array_filter($this->definitions->read()['preferences'], static fn ($p) => in_array($p['key'], $keys, true));
        if (!$preferences) {
            return [];
        }

        // Approved structured tags per observation relation: each relation
        // carries exactly one tag, so its corridor describes exactly one
        // observed aspect value of one element side over exactly the
        // observation's range. The side token identifies the element: the
        // same side on another relation is the same element (a competing
        // observation), a different side is another element of the section.
        $areas = [];
        $groups = [];
        $typesByGroup = [];
        $sidesByGroup = [];
        $valuesByGroup = [];
        foreach ($this->em->getRepository(NodeGroupTag::class)->findBy(['approved' => true]) as $entry) {
            $parsed = self::parse($entry->getTag()->getKey());
            if ($parsed === null) {
                continue;
            }
            $group = $entry->getGroup();
            $id = $group->getId();
            $groups[$id] = $group;
            $typesByGroup[$id][$parsed['type']] = true;
            $sidesByGroup[$id][$parsed['type']][$parsed['side']] = true;
            $valuesByGroup[$id][$parsed['type']][$parsed['aspect']][$entry->getTag()->getValue()][$parsed['side']] = true;
        }

        foreach ($preferences as $preference) {
            foreach ($groups as $id => $group) {
                if (!isset($typesByGroup[$id][$preference['type']])) {
                    // A part without any element of the type is unaffected:
                    // the preference rates the quality of available elements,
                    // not their absence.
                    continue;
                }
                $observed = $valuesByGroup[$id][$preference['type']][$preference['aspect']] ?? [];
                $matches = $preference['match'] === 'any_element'
                    ? array_intersect(array_keys($observed), $preference['values']) !== []
                    : array_diff(array_keys($observed), $preference['values']) === [];
                if (!$matches) {
                    continue;
                }
                // A no_alternative match only holds along the stretches of
                // the section that offer no other element with an explicitly
                // observed value outside the preference's values: such an
                // observation on another relation clips the matched corridor,
                // and covers it entirely when both observations picked the
                // same range. Unobserved aspects never clip (unknown values
                // are not alternatives).
                $exclusions = $preference['match'] === 'no_alternative'
                    ? $this->alternativeLines($id, $preference, $groups, $sidesByGroup, $valuesByGroup)
                    : [];
                $geometry = $this->area->areaGeometry($this->memberPoints($group), $exclusions);
                if ($geometry) {
                    $areas[$preference['key']][$id] = ['id' => $id, 'area' => $geometry];
                }
            }
        }

        return array_map(array_values(...), $areas);
    }

    /**
     * The member lines of the relations observing an alternative to the
     * preference's values: the same type with an approved value for the
     * aspect outside the values, on a side the matched relation does not
     * carry any element of the type on itself (another element of the
     * section). An observation of a side the matched relation also observes
     * is the same element, i.e. a competing observation, never an
     * alternative.
     *
     * @param array<int, NodeGroup> $groups
     * @param array<int, array<string, array<string, bool>>> $sidesByGroup
     *        group id => type => side => true
     * @param array<int, array<string, array<string, array<string, array<string, bool>>>>>> $valuesByGroup
     *        group id => type => aspect => value => side => true
     *
     * @return list<list<array{latitude: float, longitude: float}>>
     */
    private function alternativeLines(int $matchingId, array $preference, array $groups, array $sidesByGroup, array $valuesByGroup): array
    {
        $ownSides = $sidesByGroup[$matchingId][$preference['type']] ?? [];

        $lines = [];
        foreach ($groups as $id => $group) {
            foreach ($valuesByGroup[$id][$preference['type']][$preference['aspect']] ?? [] as $value => $sides) {
                if (!\in_array($value, $preference['values'], true) && array_diff_key($sides, $ownSides) !== []) {
                    $lines[] = $this->memberPoints($group);
                    continue 2;
                }
            }
        }

        return $lines;
    }

    /**
     * @return list<array{latitude: float, longitude: float}>
     */
    private function memberPoints(NodeGroup $group): array
    {
        return array_map(static fn ($member) => ['latitude' => $member->getNode()->getLatitude(), 'longitude' => $member->getNode()->getLongitude()], $group->getMembers()->toArray());
    }
}
