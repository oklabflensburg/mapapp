<?php

namespace App\Service;

use Doctrine\DBAL\Connection;

/**
 * Street parts are node groups whose ordered members mark the course of a
 * way, e.g. the tagged part of a street. Their area is the corridor of a
 * fixed width around the member line: what the map renders and what routing
 * uses as a GraphHopper custom area (see Routing::customModelFor()).
 */
final class StreetPartArea
{
    /**
     * Half the corridor width. The corridor must touch the way's edges for
     * GraphHopper's in_<area> checks to fire, so at 2m the member nodes must
     * sit on the way they mark. Matches the radius of the custom areas drawn
     * around tagged nodes in Routing::areaPolygon().
     */
    public const AREA_RADIUS_METERS = 2;

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * The corridor around the line through the ordered member coordinates as
     * a GeoJSON polygon geometry ([lon, lat] point order), or null when the
     * part has fewer than two points.
     *
     * @param list<array{latitude: float, longitude: float}> $points
     *
     * @return array{type: string, coordinates: list<list<list<float>>>}|null
     */
    public function areaGeometry(array $points): ?array
    {
        if (\count($points) < 2) {
            return null;
        }

        $lineString = implode(',', array_map(
            static fn (array $point): string => $point['longitude'].' '.$point['latitude'],
            $points,
        ));

        // Buffering the geography gives the fixed width in meters; PostGIS
        // closes the ring and GeoJSON keeps the [lon, lat] order the frontend
        // and GraphHopper expect.
        $geoJson = $this->connection->fetchOne(
            'SELECT ST_AsGeoJSON(ST_Buffer(ST_GeomFromText(:line, 4326)::geography, :radius))',
            ['line' => 'LINESTRING('.$lineString.')', 'radius' => self::AREA_RADIUS_METERS],
        );

        if (!\is_string($geoJson)) {
            return null;
        }

        /** @var array{type: string, coordinates: list<list<list<float>>>}|null $geometry */
        $geometry = json_decode($geoJson, true);

        return $geometry;
    }
}
