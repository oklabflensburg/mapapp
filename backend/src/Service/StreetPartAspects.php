<?php

namespace App\Service;

use App\Entity\NodeGroup;
use App\Entity\NodeGroupTag;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/** Cross-sections are a view of ordinary, moderated relation tags. */
final class StreetPartAspects
{
    /**
     * Element position tokens across the cross-section, in canonical order
     * (each side axis keeps `center` between its opposite sides).
     */
    public const DIRECTIONS = ['west', 'south', 'center', 'north', 'east'];

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

    /** @return array{type: string, direction: string, index: string, aspect: string}|null */
    public static function parse(string $key): ?array
    {
        if (!preg_match('/\Away\.([^.\s]+)\.(north|south|east|west|center)\.([1-9][0-9]*)\.([^.\s]+)\z/u', $key, $match) || mb_strlen($key) > 255) {
            return null;
        }

        return ['type' => $match[1], 'direction' => $match[2], 'index' => $match[3], 'aspect' => $match[4]];
    }

    /**
     * The previous direction-less grammar `way.<type>.<index>.<aspect>`, kept
     * for the conversion chain (see street-part-aspects R8):
     * `app:aspects:convert-legacy` emits it, `app:aspects:migrate-directions`
     * rewrites it into the direction grammar. New saves reject these keys.
     * Validation reuses the current grammar parser (a legacy key is valid
     * exactly when it becomes valid with a `center` direction inserted), so
     * legacy keys long enough for the suffix push past the length limit are
     * rejected — they predate validation and do not exist in practice.
     *
     * @return array{type: string, index: string, aspect: string}|null
     */
    public static function parseLegacy(string $key): ?array
    {
        if (!str_starts_with($key, 'way.') || substr_count($key, '.') !== 3) {
            return null;
        }
        [$namespace, $type, $index, $aspect] = explode('.', $key);

        $parsed = self::parse($namespace.'.'.$type.'.center.'.$index.'.'.$aspect);

        return $parsed === null ? null
            : ['type' => $parsed['type'], 'index' => $parsed['index'], 'aspect' => $parsed['aspect']];
    }

    public function validate(array $tags, NodeGroup $group, User $user, bool $admin): void
    {
        $structured = array_filter($tags, static fn ($tag) => str_starts_with($tag['key'], 'way.'));
        $hasStored = false;
        foreach ($group->getGroupTags() as $entry) {
            $hasStored = $hasStored || ($entry->getCreatedByUser() === $user && str_starts_with($entry->getTag()->getKey(), 'way.'));
        }
        if (!$structured && !$hasStored) {
            return;
        }
        $definitions = [];
        foreach ($this->catalog() as $type) {
            foreach ($type['attributes'] as $attribute) {
                $definitions[$type['name']][$attribute['key']] = $attribute['values'];
            }
        }
        $owned = [];
        $approved = [];
        $positions = [];
        foreach ($group->getGroupTags() as $entry) {
            $tag = $entry->getTag();
            if ($entry->getCreatedByUser() === $user) {
                $owned[$tag->getKey()][$tag->getValue()] = true;
            }
            if ($entry->isApproved()) {
                $approved[$tag->getKey()][$tag->getValue()] = true;
                $parsed = self::parse($tag->getKey());
                if ($parsed && !$admin) {
                    $positions[$parsed['direction'].'.'.$parsed['index']] = $parsed['type'];
                }
            }
        }
        $keys = [];
        foreach ($structured as $tag) {
            $key = $tag['key'];
            $value = $tag['value'];
            $parsed = self::parse($key);
            if (!$parsed || isset($keys[$key])) {
                throw new UnprocessableEntityHttpException('Malformed or duplicate structured tag key.');
            }
            $keys[$key] = true;
            ['type' => $type, 'direction' => $direction, 'index' => $index, 'aspect' => $aspect] = $parsed;
            $position = $direction.'.'.$index;
            if (isset($positions[$position]) && $positions[$position] !== $type) {
                throw new UnprocessableEntityHttpException('A cross-section position can have only one type.');
            }
            $positions[$position] = $type;
            $unchanged = isset($owned[$key][$value]) || isset($approved[$key][$value]);
            if (!$unchanged && (!isset($definitions[$type][$aspect]) || !in_array($value, $definitions[$type][$aspect], true))) {
                throw new UnprocessableEntityHttpException('Unknown cross-section type, aspect or value.');
            }
            if (!$admin && isset($approved[$key]) && !isset($approved[$key][$value])) {
                throw new UnprocessableEntityHttpException('Approved cross-section tags are read-only.');
            }
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

        // Approved structured tags per street part: which element types exist
        // (any index, any aspect) and which values were observed for each
        // type/aspect. Elements without an approved value for an aspect
        // contribute the type but no value: unknown values never act as
        // alternatives.
        $areas = [];
        $groups = [];
        $typesByGroup = [];
        $valuesByGroup = [];
        $geometryByGroup = [];
        foreach ($this->em->getRepository(NodeGroupTag::class)->findBy(['approved' => true]) as $entry) {
            $parsed = self::parse($entry->getTag()->getKey());
            if ($parsed === null) {
                continue;
            }
            $group = $entry->getGroup();
            $id = $group->getId();
            $groups[$id] = $group;
            $typesByGroup[$id][$parsed['type']] = true;
            $valuesByGroup[$id][$parsed['type']][$parsed['aspect']][$entry->getTag()->getValue()] = true;
        }

        foreach ($preferences as $preference) {
            foreach ($groups as $id => $group) {
                if (!isset($typesByGroup[$id][$preference['type']])) {
                    // A part without any element of the type is unaffected:
                    // the preference rates the quality of available elements,
                    // not their absence.
                    continue;
                }
                $observed = array_keys($valuesByGroup[$id][$preference['type']][$preference['aspect']] ?? []);
                $matches = $preference['match'] === 'any_element'
                    ? array_intersect($observed, $preference['values']) !== []
                    : array_diff($observed, $preference['values']) === [];
                if (!$matches) {
                    continue;
                }
                if (!array_key_exists($id, $geometryByGroup)) {
                    $geometryByGroup[$id] = $this->area->areaGeometry(array_map(static fn ($member) => ['latitude' => $member->getNode()->getLatitude(), 'longitude' => $member->getNode()->getLongitude()], $group->getMembers()->toArray()));
                }
                if ($geometryByGroup[$id]) {
                    $areas[$preference['key']][$id] = ['id' => $id, 'area' => $geometryByGroup[$id]];
                }
            }
        }

        return array_map(array_values(...), $areas);
    }
}
