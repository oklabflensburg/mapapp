<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Finds walking routes through the GraphHopper routing service.
 */
final class Routing
{
    // The only routing profile configured in backend/routing/graphhopper.yml.
    // Its custom model keeps stairs passable; per-request custom models add
    // the user's tag priorities on top (see customModelFor()).
    public const PROFILE = 'foot';

    // Radius of the custom area drawn around a tagged node: large enough to
    // cover the way at the node, small enough to leave parallel ways alone.
    private const AREA_RADIUS_METERS = 20;

    // Priority factors per level. Avoid levels penalize the tagged ways;
    // prefer levels penalize everything ELSE (the only direction
    // per-request custom models allow, see customModelFor()).
    private const FACTOR_AVOID = '0';
    private const FACTOR_TRY_TO_AVOID = '0.3';
    private const FACTOR_PREFER = '0.8';
    private const FACTOR_STRONGLY_PREFER = '0.5';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire('%env(GRAPHHOPPER_BASE_URL)%')]
        private readonly string $graphHopperBaseUrl,
    ) {
    }

    /**
     * @param array<string, string> $tagPriorities tag => priority level, as
     *                                             returned by TagPriorityManager::getPriorities()
     * @param array<string, list<array{latitude: float, longitude: float}>> $taggedNodes
     *        app nodes tagged with a prioritized tag, grouped by tag, as
     *        returned by NodeRepository::findTaggedNodeCoordinates()
     *
     * @return array{distance: float, time: int, points: list<list<float>>}
     *         distance in meters, time in seconds, points as [lat, lon] pairs
     *         along the route
     *
     * @throws NoRouteFoundException       when GraphHopper cannot connect the points
     * @throws RoutingUnavailableException when GraphHopper cannot be reached
     */
    public function findShortestFootRoute(float $fromLat, float $fromLon, float $toLat, float $toLon, array $tagPriorities = [], array $taggedNodes = []): array
    {
        $customModel = $this->customModelFor($tagPriorities, $taggedNodes);

        if ($customModel === null) {
            // Without tag preferences the profile's custom model applies as
            // configured in graphhopper.yml; a plain GET request is enough.
            // GraphHopper expects repeated `point` parameters, which Symfony's
            // query array encoding (point[0]=...) cannot express; the
            // coordinates are range-validated floats, so building the string
            // is safe.
            $url = sprintf(
                '%s/route?point=%.6F,%.6F&point=%.6F,%.6F&profile=%s&points_encoded=false',
                $this->graphHopperBaseUrl,
                $fromLat,
                $fromLon,
                $toLat,
                $toLon,
                self::PROFILE,
            );

            try {
                $data = $this->httpClient->request('GET', $url, ['timeout' => 15])->toArray();
            } catch (ClientExceptionInterface) {
                // GraphHopper reports unroutable point pairs as a client error;
                // any other client error cannot happen for this fixed request.
                throw new NoRouteFoundException();
            } catch (TransportExceptionInterface) {
                throw new RoutingUnavailableException();
            }
        } else {
            // Per-request custom models are only supported by POST /route;
            // the statements are appended to the profile's own custom model.
            // GraphHopper expects [lon, lat] point order here.
            $body = [
                'points' => [[$fromLon, $fromLat], [$toLon, $toLat]],
                'profile' => self::PROFILE,
                'points_encoded' => false,
                'custom_model' => $customModel,
            ];

            try {
                $data = $this->httpClient->request('POST', $this->graphHopperBaseUrl.'/route', [
                    'json' => $body,
                    'timeout' => 15,
                ])->toArray();
            } catch (ClientExceptionInterface) {
                throw new NoRouteFoundException();
            } catch (TransportExceptionInterface) {
                throw new RoutingUnavailableException();
            }
        }

        $path = $data['paths'][0] ?? null;
        if (!\is_array($path)) {
            throw new NoRouteFoundException();
        }

        // GraphHopper returns [lon, lat] pairs; the map frontend expects
        // Leaflet's [lat, lon] order.
        $points = [];
        foreach ($path['points']['coordinates'] as $coordinate) {
            $points[] = [$coordinate[1], $coordinate[0]];
        }

        return [
            'distance' => round((float) ($path['distance'] ?? 0)),
            'time' => (int) round((float) ($path['time'] ?? 0) / 1000),
            'points' => $points,
        ];
    }

    /**
     * Translates the user's tag priorities into a GraphHopper custom model
     * that is appended to the profile's custom model. Two mechanisms are used:
     *
     * - OSM encoded values: the "stairs" tag maps to a road_class STEPS rule
     *   for ways imported from OSM. Only the avoid levels can use it:
     *   per-request statements can only lower edge priority (GraphHopper
     *   rejects values above 1 with prepared landmarks), so OSM stairs
     *   cannot be preferred.
     * - Custom areas: app nodes tagged with the tag each become a small
     *   polygon around their coordinates. Avoid levels penalize the areas;
     *   prefer levels penalize everything outside them, which is the
     *   documented way to prefer locations when boosting is impossible.
     *
     * @param array<string, string>             $tagPriorities
     * @param array<string, list<array{latitude: float, longitude: float}>> $taggedNodes
     *
     * @return array{priority: list<array<string, string>>, areas?: array{type: string, features: list<array<string, mixed>>}}|null
     */
    public function customModelFor(array $tagPriorities, array $taggedNodes = []): ?array
    {
        $statements = [];
        $features = [];

        foreach (TagPriorityManager::tags() as $tag) {
            $level = $tagPriorities[$tag] ?? TagPriorityManager::DEFAULT_LEVEL;

            if ($tag === 'stairs') {
                if ($level === 'avoid') {
                    $statements[] = ['if' => 'road_class == STEPS', 'multiply_by' => self::FACTOR_AVOID];
                } elseif ($level === 'try_to_avoid') {
                    $statements[] = ['if' => 'road_class == STEPS', 'multiply_by' => self::FACTOR_TRY_TO_AVOID];
                }
            }

            $nodes = $taggedNodes[$tag] ?? [];
            if ($nodes === []) {
                continue;
            }

            $areaIds = [];
            foreach (array_values($nodes) as $index => $node) {
                $areaId = $this->areaId($tag, $index);
                $areaIds[] = $areaId;
                $features[] = [
                    'type' => 'Feature',
                    'id' => $areaId,
                    'properties' => new \stdClass(),
                    'geometry' => [
                        'type' => 'Polygon',
                        'coordinates' => [$this->areaPolygon($node['longitude'], $node['latitude'])],
                    ],
                ];
            }

            if ($level === 'avoid' || $level === 'try_to_avoid') {
                $factor = $level === 'avoid' ? self::FACTOR_AVOID : self::FACTOR_TRY_TO_AVOID;
                $conditions = array_map(static fn (string $id): string => 'in_'.$id, $areaIds);
                $statements[] = ['if' => implode(' || ', $conditions), 'multiply_by' => $factor];
            } elseif ($level === 'prefer' || $level === 'strongly_prefer') {
                $factor = $level === 'prefer' ? self::FACTOR_PREFER : self::FACTOR_STRONGLY_PREFER;
                $conditions = array_map(static fn (string $id): string => '!in_'.$id, $areaIds);
                $statements[] = ['if' => implode(' && ', $conditions), 'multiply_by' => $factor];
            }
        }

        if ($statements === []) {
            return null;
        }

        $customModel = ['priority' => $statements];
        if ($features !== []) {
            $customModel['areas'] = ['type' => 'FeatureCollection', 'features' => $features];
        }

        return $customModel;
    }

    /**
     * A stable area id usable in custom model conditions (`in_<id>`), unique
     * per tag and node index within the request.
     */
    private function areaId(string $tag, int $index): string
    {
        return 'tag_'.preg_replace('/[^a-z0-9_]+/', '_', mb_strtolower($tag)).'_'.($index + 1);
    }

    /**
     * A closed octagon ring around the coordinates, [lon, lat] point order as
     * GeoJSON requires.
     *
     * @return list<list<float>>
     */
    private function areaPolygon(float $longitude, float $latitude): array
    {
        $ring = [];
        for ($i = 0; $i <= 8; ++$i) {
            $angle = (M_PI / 4) * $i;
            $ring[] = [
                $longitude + self::AREA_RADIUS_METERS * cos($angle) / (111_320 * cos(deg2rad($latitude))),
                $latitude + self::AREA_RADIUS_METERS * sin($angle) / 111_320,
            ];
        }

        return $ring;
    }
}
