<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class RoutingControllerTest extends WebTestCase
{
    public function testRouteReturnsShortestWalk(): void
    {
        $client = static::createClient();
        static::getContainer()->set('http_client', new MockHttpClient(
            new MockResponse((string) json_encode([
                'paths' => [
                    [
                        'distance' => 1234.56,
                        'time' => 900000,
                        'points' => ['coordinates' => [[9.43, 54.79], [9.44, 54.80]]],
                    ],
                ],
            ])),
        ));

        $client->request('GET', '/api/route?fromLat=54.791&fromLon=9.437&toLat=54.801&toLon=9.447');

        self::assertResponseIsSuccessful();
        self::assertSame(
            ['distance' => 1235, 'time' => 900, 'points' => [[54.79, 9.43], [54.80, 9.44]]],
            json_decode((string) $client->getResponse()->getContent(), true),
        );
    }

    public function testRouteReportsUnroutablePoints(): void
    {
        $client = static::createClient();
        static::getContainer()->set('http_client', new MockHttpClient(
            new MockResponse('{"message":"Connection between locations not found"}', ['http_code' => 400]),
        ));

        $client->request('GET', '/api/route?fromLat=54.791&fromLon=9.437&toLat=54.801&toLon=9.447');

        self::assertSame(422, $client->getResponse()->getStatusCode());
        self::assertSame(
            ['error' => 'no_route'],
            json_decode((string) $client->getResponse()->getContent(), true),
        );
    }

    public function testRouteRejectsMissingCoordinates(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/route?fromLat=54.791&fromLon=9.437');

        self::assertSame(422, $client->getResponse()->getStatusCode());
    }

    public function testRouteRejectsOutOfRangeLatitude(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/route?fromLat=95&fromLon=9.437&toLat=54.801&toLon=9.447');

        self::assertSame(422, $client->getResponse()->getStatusCode());
    }
}
