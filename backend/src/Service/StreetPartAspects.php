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

    /** @return array{type: string, index: string, aspect: string}|null */
    public static function parse(string $key): ?array
    {
        if (!preg_match('/\Away\.([^.\s]+)\.([1-9][0-9]*)\.([^.\s]+)\z/u', $key, $match) || mb_strlen($key) > 255) {
            return null;
        }

        return ['type' => $match[1], 'index' => $match[2], 'aspect' => $match[3]];
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
                    $positions[$parsed['index']] = $parsed['type'];
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
            ['type' => $type, 'index' => $index, 'aspect' => $aspect] = $parsed;
            if (isset($positions[$index]) && $positions[$index] !== $type) {
                throw new UnprocessableEntityHttpException('A cross-section position can have only one type.');
            }
            $positions[$index] = $type;
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
        $areas = [];
        $geometryByGroup = [];
        foreach ($this->em->getRepository(NodeGroupTag::class)->findBy(['approved' => true]) as $entry) {
            $tag = $entry->getTag();
            $parsed = self::parse($tag->getKey());
            if (!$parsed) {
                continue;
            }
            foreach ($preferences as $preference) {
                if ($parsed['type'] !== $preference['type'] || $parsed['aspect'] !== $preference['aspect'] || !in_array($tag->getValue(), $preference['values'], true)) {
                    continue;
                }
                $group = $entry->getGroup();
                $id = $group->getId();
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
