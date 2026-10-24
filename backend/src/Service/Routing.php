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
    public const DEFAULT_PROFILE = 'foot_shortest';

    /**
     * Routing profiles configured in backend/routing/graphhopper.yml; also
     * the allowed values of the /api/route profile query parameter.
     *
     * @return list<string>
     */
    public static function profiles(): array
    {
        return ['foot_shortest', 'foot_wheelchair'];
    }

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire('%env(GRAPHHOPPER_BASE_URL)%')]
        private readonly string $graphHopperBaseUrl,
    ) {
    }

    /**
     * @return array{distance: float, time: int, points: list<list<float>>}
     *         distance in meters, time in seconds, points as [lat, lon] pairs
     *         along the route
     *
     * @param string $profile one of self::profiles(); defaults to the
     *                          shortest walking route
     *
     * @throws NoRouteFoundException       when GraphHopper cannot connect the points
     * @throws RoutingUnavailableException when GraphHopper cannot be reached
     */
    public function findShortestFootRoute(float $fromLat, float $fromLon, float $toLat, float $toLon, string $profile = self::DEFAULT_PROFILE): array
    {
        // GraphHopper expects repeated `point` parameters, which Symfony's
        // query array encoding (point[0]=...) cannot express; the coordinates
        // are range-validated floats, so building the string is safe.
        $url = sprintf(
            '%s/route?point=%.6F,%.6F&point=%.6F,%.6F&profile=%s&points_encoded=false',
            $this->graphHopperBaseUrl,
            $fromLat,
            $fromLon,
            $toLat,
            $toLon,
            $profile,
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
}
