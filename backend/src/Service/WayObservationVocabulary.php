<?php

namespace App\Service;

use App\Api\SaveUserNodeRequest;
use App\Entity\{Node, User};
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
