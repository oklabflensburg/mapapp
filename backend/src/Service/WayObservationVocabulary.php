<?php

namespace App\Service;

use App\Api\SaveUserNodeRequest;
use App\Entity\{Node, NodeGroup, User};
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/** Classifies against the shared OSM export, never a client-supplied highway value. */
final class WayObservationVocabulary
{
    public function __construct(
        private readonly EditingVocabulary $vocabulary,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir
    ) {
    }

    public function validate(SaveUserNodeRequest $request, ?Node $node, User $user): void
    {
        if ($request->provider !== 'OSM' || !preg_match('/^w\d+$/D', $request->providerNodeId)) {
            return;
        }
        $feature = $this->feature($request->providerNodeId);
        if ($feature && !isset($feature['properties']['highway']) && !in_array($feature['geometry']['type'] ?? null, ['LineString', 'MultiLineString'], true)) {
            return;
        }
        $entries = [];
        $definitions = $this->vocabulary->read();
        $type = $feature['properties']['highway'] ?? null;
        foreach ($definitions[$type ?? ''] ?? [] as $entry) {
            $entries[$entry['key']] = $entry['values'];
        }
        foreach ($request->tags as $tag) {
            // Approved rows are echoed by clients. Grandfather only keys the
            // current editor already owns; this does not authorize new keys.
            $grandfathered = false;
            $unchanged = false;
            foreach ($node?->getNodeTags() ?? [] as $old) {
                if ($old->getTag()->getKey() === $tag['key'] && ($old->isApproved() && $old->getTag()->getValue() === $tag['value'] || !$old->isApproved() && $old->getCreatedByUser() === $user)) {
                    $grandfathered = true;
                }
                if ($old->getTag()->getKey() === $tag['key'] && $old->getTag()->getValue() === $tag['value'] && ($old->isApproved() || $old->getCreatedByUser() === $user)) {
                    $unchanged = true;
                }
            }
            if (!array_key_exists($tag['key'], $entries)) {
                if ($grandfathered) {
                    continue;
                }
                throw new UnprocessableEntityHttpException('Observation key is not allowed for this way type.');
            }
            if ($entries[$tag['key']] !== [] && !in_array($tag['value'], $entries[$tag['key']], true) && !$unchanged) {
                throw new UnprocessableEntityHttpException('Observation value is not allowed for this way type.');
            }
        }
    }

    /**
     * Whole-way observation relations are anchored on the way identity node
     * (a single OSM member with a w… feature id); their one observation key
     * and value are restricted by the way type's controlled vocabulary,
     * exactly like the former node-anchored way observations.
     *
     * @param array{key: string, value: string} $entry
     */
    public function validateRelation(NodeGroup $group, array $entry, User $user): void
    {
        $wayId = null;
        foreach ($group->getMembers() as $member) {
            $node = $member->getNode();
            if ($node->getNodeProvider()->getName() !== 'OSM' || !preg_match('/^w\d+$/D', (string) $node->getProviderNodeId())) {
                return; // Not a whole-way observation; free-form applies.
            }
            $wayId ??= (string) $node->getProviderNodeId();
        }
        if ($wayId === null) {
            return;
        }

        // An unchanged echo of what the relation already carries stays
        // valid even if the catalog moved on.
        foreach ($group->getGroupTags() as $existing) {
            if ($existing->getTag()->getKey() === $entry['key'] && ($existing->isApproved() || $existing->getCreatedByUser() === $user)) {
                return;
            }
        }

        $feature = $this->feature($wayId);
        if ($feature && !isset($feature['properties']['highway']) && !in_array($feature['geometry']['type'] ?? null, ['LineString', 'MultiLineString'], true)) {
            return;
        }
        $entries = [];
        $definitions = $this->vocabulary->read();
        foreach ($definitions[$feature['properties']['highway'] ?? ''] ?? [] as $definition) {
            $entries[$definition['key']] = $definition['values'];
        }
        if (!array_key_exists($entry['key'], $entries)) {
            throw new UnprocessableEntityHttpException('Observation key is not allowed for this way type.');
        }
        if ($entries[$entry['key']] !== [] && !in_array($entry['value'], $entries[$entry['key']], true)) {
            throw new UnprocessableEntityHttpException('Observation value is not allowed for this way type.');
        }
    }

    private function feature(string $id): ?array
    {
        $path = $this->projectDir . '/osm-data/flensburg.geojson';
        if (!is_file($path)) {
            $path = $this->projectDir . '/../public/data/flensburg.geojson';
        }
        if (!is_file($path)) {
            return null;
        }
        $data = json_decode(file_get_contents($path), true);
        foreach ($data['features'] ?? [] as $feature) {
            if (($feature['properties']['@id'] ?? $feature['id'] ?? null) === $id) {
                return $feature;
            }
        }
        return null;
    }
}
