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
     * Half the corridor width. The corridor must reach the way's edges for
     * GraphHopper's in_<area> checks to fire, so the member nodes must sit
     * on the way they mark. GraphHopper matches the exact OSM edge geometry:
     * an area around way-aligned members still fires down to a few
     * centimeters in half-width, so 1m keeps a large safety margin while
     * the rendered corridor stays slim.
     */
    public const AREA_RADIUS_METERS = 1;

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * The corridor around the line through the ordered member coordinates as
     * a GeoJSON geometry ([lon, lat] point order), or null when the part has
     * fewer than two points. Without exclusion lines the geometry is a
     * Polygon; with them, the corridor is clipped by the corridors around
     * those lines (ST_Difference), so only stretches no exclusion corridor
     * reaches remain covered. The clipped geometry may be a MultiPolygon,
     * and is null when an exclusion corridor covers it entirely.
     *
     * @param list<array{latitude: float, longitude: float}> $points
     * @param list<list<array{latitude: float, longitude: float}>> $exclusionLines
     *        lines whose corridors are cut out of the result
     *
     * @return array{type: string, coordinates: list<list<list<float>>>}|null
     */
    public function areaGeometry(array $points, array $exclusionLines = []): ?array
    {
        if (\count($points) < 2) {
            return null;
        }

        $lineString = implode(',', array_map(
            static fn (array $point): string => $point['longitude'].' '.$point['latitude'],
            $points,
        ));

        $rings = [];
        foreach ($exclusionLines as $line) {
            if (\count($line) >= 2) {
                $rings[] = '('.implode(',', array_map(
                    static fn (array $point): string => $point['longitude'].' '.$point['latitude'],
                    $line,
                )).')';
            }
        }

        // Buffering the geography gives the fixed width in meters; PostGIS
        // closes the ring and GeoJSON keeps the [lon, lat] order the frontend
        // and GraphHopper expect. Buffering a multilinestring dilates every
        // component line at once, i.e. the union of the exclusion corridors.
        $buffer = $rings === []
            ? 'ST_Buffer(ST_GeomFromText(:line, 4326)::geography, :radius)'
            : 'ST_Difference(
                   ST_Buffer(ST_GeomFromText(:line, 4326)::geography, :radius)::geometry,
                   ST_Buffer(ST_GeomFromText(:exclusions, 4326)::geography, :radius)::geometry
               )';
        $parameters = ['line' => 'LINESTRING('.$lineString.')', 'radius' => self::AREA_RADIUS_METERS];
        if ($rings !== []) {
            $parameters['exclusions'] = 'MULTILINESTRING('.implode(',', $rings).')';
        }

        // A fully covered corridor clips to an empty geometry, which the
        // WHERE clause drops: fetchOne then finds no row.
        $geoJson = $this->connection->fetchOne(
            'SELECT ST_AsGeoJSON(clip.clipped) FROM (SELECT '.$buffer.' AS clipped) clip WHERE NOT ST_IsEmpty(clip.clipped::geometry)',
            $parameters,
        );

        if (!\is_string($geoJson)) {
            return null;
        }

        /** @var array{type: string, coordinates: list<list<list<float>>>}|null $geometry */
        $geometry = json_decode($geoJson, true);

        return $geometry;
    }
}
