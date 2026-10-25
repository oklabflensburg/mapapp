<?php

namespace App\Tests\Service;

use App\Service\Routing;
use App\Service\RoutingUnavailableException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class RoutingTest extends KernelTestCase
{
    public function testUnreachableGraphHopperIsReportedAsUnavailable(): void
    {
        // Nothing listens on this local port, so the request fails with the
        // transport error the service maps to RoutingUnavailableException.
        $routing = new Routing(
            static::getContainer()->get('http_client'),
            'http://127.0.0.1:1',
        );

        $this->expectException(RoutingUnavailableException::class);
        $routing->findShortestFootRoute(54.791, 9.437, 54.801, 9.447);
    }

    public function testAvoidingStairsExcludesSteps(): void
    {
        $routing = new Routing(
            static::getContainer()->get('http_client'),
            'http://127.0.0.1:1',
        );

        // The admin mapped the stairs tag to the STEPS road class (the
        // mapping is a route_preference_tags setting, not hardcoded).
        self::assertSame(
            ['priority' => [['if' => 'road_class == STEPS', 'multiply_by' => '0']]],
            $routing->customModelFor(['stairs' => 'avoid'], [], ['stairs' => 'STEPS']),
        );
    }

    public function testTryingToAvoidStairsPenalizesSteps(): void
    {
        $routing = new Routing(
            static::getContainer()->get('http_client'),
            'http://127.0.0.1:1',
        );

        self::assertSame(
            ['priority' => [['if' => 'road_class == STEPS', 'multiply_by' => '0.3']]],
            $routing->customModelFor(['stairs' => 'try_to_avoid'], [], ['stairs' => 'STEPS']),
        );
    }

    public function testAvoidingAnyTagWithARoadClassPenalizesThatRoadClass(): void
    {
        $routing = new Routing(
            static::getContainer()->get('http_client'),
            'http://127.0.0.1:1',
        );

        // The road class mapping is not limited to stairs: whatever the
        // admin mapped applies to the configured class.
        self::assertSame(
            ['priority' => [['if' => 'road_class == PATH', 'multiply_by' => '0']]],
            $routing->customModelFor(['unpaved' => 'avoid'], [], ['unpaved' => 'PATH']),
        );
    }

    public function testAvoidingStairsAlsoAvoidsTaggedNodes(): void
    {
        $routing = new Routing(
            static::getContainer()->get('http_client'),
            'http://127.0.0.1:1',
        );

        $model = $routing->customModelFor(
            ['stairs' => 'avoid'],
            ['stairs' => [['latitude' => 54.3235, 'longitude' => 10.1385]]],
            ['stairs' => 'STEPS'],
        );

        self::assertNotNull($model);
        self::assertSame([
            ['if' => 'road_class == STEPS', 'multiply_by' => '0'],
            ['if' => 'in_tag_stairs_1', 'multiply_by' => '0'],
        ], $model['priority']);
        self::assertSame('FeatureCollection', $model['areas']['type']);
        $feature = $model['areas']['features'][0];
        self::assertSame('tag_stairs_1', $feature['id']);
        self::assertSame('Polygon', $feature['geometry']['type']);
        $ring = $feature['geometry']['coordinates'][0];
        // Eight corners plus the closing point, which equals the first.
        self::assertCount(9, $ring);
        self::assertSame($ring[0], $ring[8]);
        // The first corner sits east of the node.
        self::assertEqualsWithDelta(10.1385 + 20 / (111320 * cos(deg2rad(54.3235))), $ring[0][0], 1e-9);
        self::assertEqualsWithDelta(54.3235, $ring[0][1], 1e-9);
    }

    public function testTaggedNodesWithoutAvoidanceProduceNoModel(): void
    {
        $routing = new Routing(
            static::getContainer()->get('http_client'),
            'http://127.0.0.1:1',
        );

        // The node exists, but the user's priority keeps the profile default.
        self::assertNull($routing->customModelFor(
            ['stairs' => 'neutral'],
            ['stairs' => [['latitude' => 54.3235, 'longitude' => 10.1385]]],
        ));
    }

    public function testAvoidingToiletsDetoursAroundTaggedNodes(): void
    {
        $routing = new Routing(
            static::getContainer()->get('http_client'),
            'http://127.0.0.1:1',
        );

        $model = $routing->customModelFor(
            ['stairs' => 'neutral', 'toilets' => 'avoid'],
            ['toilets' => [['latitude' => 54.3235, 'longitude' => 10.1385]]],
        );

        // Toilets is a custom tag without an OSM encoded value, so only the
        // area statement is produced.
        self::assertSame(
            ['priority' => [['if' => 'in_tag_toilets_1', 'multiply_by' => '0']]],
            ['priority' => $model['priority']],
        );
        self::assertSame('tag_toilets_1', $model['areas']['features'][0]['id']);
    }

    public function testPreferringToiletsPenalizesEverythingOutsideTaggedNodes(): void
    {
        $routing = new Routing(
            static::getContainer()->get('http_client'),
            'http://127.0.0.1:1',
        );

        $model = $routing->customModelFor(
            ['toilets' => 'strongly_prefer'],
            ['toilets' => [
                ['latitude' => 54.3235, 'longitude' => 10.1385],
                ['latitude' => 54.3201, 'longitude' => 10.1302],
            ]],
        );

        // Boosting the areas themselves is impossible per request; preference
        // is expressed by penalizing all edges outside the areas.
        self::assertSame(
            ['priority' => [['if' => '!in_tag_toilets_1 && !in_tag_toilets_2', 'multiply_by' => '0.5']]],
            ['priority' => $model['priority']],
        );
        self::assertCount(2, $model['areas']['features']);
    }

    #[DataProvider('providerNeutralLevels')]
    public function testNeutralAndPreferLevelsKeepTheProfileDefault(string $level): void
    {
        $routing = new Routing(
            static::getContainer()->get('http_client'),
            'http://127.0.0.1:1',
        );

        // Prefer levels cannot raise edge priority above the profile's
        // custom model (GraphHopper rejects values above 1 per request), so
        // they keep the profile default, even for a mapped road class.
        self::assertNull($routing->customModelFor(['stairs' => $level], [], ['stairs' => 'STEPS']));
        self::assertNull($routing->customModelFor([]));
    }

    public static function providerNeutralLevels(): iterable
    {
        yield ['neutral'];
        yield ['prefer'];
        yield ['strongly_prefer'];
    }
}
