<?php

namespace App\Tests\Service;

use App\Service\Routing;
use App\Service\RoutingUnavailableException;
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
}
