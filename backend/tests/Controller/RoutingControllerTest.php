<?php

namespace App\Tests\Controller;

use App\Api\SaveTagPrioritiesRequest;
use App\Entity\Node;
use App\Entity\NodeTag;
use App\Entity\User;
use App\Repository\NodeProviderRepository;
use App\Repository\NodeTypeRepository;
use App\Repository\TagRepository;
use App\Service\TagPriorityManager;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class RoutingControllerTest extends WebTestCase
{
    use CreatesUsers;
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

    public function testRouteUsesTheFootProfileForAnonymousVisitors(): void
    {
        $client = static::createClient();
        $requestedUrls = [];
        static::getContainer()->set('http_client', new MockHttpClient(
            function (string $method, string $url, array $options) use (&$requestedUrls): MockResponse {
                $requestedUrls[] = $method.' '.$url;

                return new MockResponse((string) json_encode([
                    'paths' => [
                        [
                            'distance' => 1234.56,
                            'time' => 900000,
                            'points' => ['coordinates' => [[9.43, 54.79], [9.44, 54.80]]],
                        ],
                    ],
                ]));
            },
        ));

        $client->request('GET', '/api/route?fromLat=54.791&fromLon=9.437&toLat=54.801&toLon=9.447');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $requestedUrls);
        self::assertStringContainsString('profile=foot', $requestedUrls[0]);
        // Without tag preferences no custom model is sent; the profile's
        // own custom model from graphhopper.yml applies.
        self::assertStringContainsString('GET ', $requestedUrls[0]);
    }

    public function testRouteAppliesTheUsersStairsPriority(): void
    {
        $client = static::createClient();
        $user = $this->createUser('route-priorities@example.test', []);
        $client->loginUser($user);
        // No node tagged stairs may linger from another test: it would add
        // an area statement to the asserted custom model.
        $this->removeTaggedNode('stairs');
        static::getContainer()->get(TagPriorityManager::class)->save(
            $user,
            new SaveTagPrioritiesRequest(['stairs' => 'avoid']),
        );

        $requests = [];
        static::getContainer()->set('http_client', new MockHttpClient(
            function (string $method, string $url, array $options) use (&$requests): MockResponse {
                $requests[] = ['method' => $method, 'url' => $url, 'body' => $options['body'] ?? null];

                return new MockResponse((string) json_encode([
                    'paths' => [
                        [
                            'distance' => 1234.56,
                            'time' => 900000,
                            'points' => ['coordinates' => [[9.43, 54.79], [9.44, 54.80]]],
                        ],
                    ],
                ]));
            },
        ));

        $client->request('GET', '/api/route?fromLat=54.791&fromLon=9.437&toLat=54.801&toLon=9.447');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $requests);
        self::assertSame('POST', $requests[0]['method']);
        $body = json_decode((string) $requests[0]['body'], true);
        self::assertSame('foot', $body['profile']);
        self::assertSame(
            [['if' => 'road_class == STEPS', 'multiply_by' => '0']],
            $body['custom_model']['priority'],
        );
    }

    public function testRouteForAUserWithoutPreferencesSendsNoCustomModel(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser('route-neutral@example.test', []));

        $requests = [];
        static::getContainer()->set('http_client', new MockHttpClient(
            function (string $method, string $url, array $options) use (&$requests): MockResponse {
                $requests[] = ['method' => $method, 'url' => $url];

                return new MockResponse((string) json_encode([
                    'paths' => [
                        [
                            'distance' => 1234.56,
                            'time' => 900000,
                            'points' => ['coordinates' => [[9.43, 54.79], [9.44, 54.80]]],
                        ],
                    ],
                ]));
            },
        ));

        $client->request('GET', '/api/route?fromLat=54.791&fromLon=9.437&toLat=54.801&toLon=9.447');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $requests);
        self::assertSame('GET', $requests[0]['method']);
    }

    public function testRouteDetoursAroundAppNodesTaggedStairs(): void
    {
        $client = static::createClient();
        $user = $this->createUser('route-node-priorities@example.test', []);
        $client->loginUser($user);
        $this->removeTaggedNode('stairs');
        $this->createTaggedNode($user, 'stairs', 54.3235, 10.1385);
        static::getContainer()->get(TagPriorityManager::class)->save(
            $user,
            new SaveTagPrioritiesRequest(['stairs' => 'avoid']),
        );

        $requests = [];
        static::getContainer()->set('http_client', new MockHttpClient(
            function (string $method, string $url, array $options) use (&$requests): MockResponse {
                $requests[] = ['method' => $method, 'body' => $options['body'] ?? null];

                return new MockResponse((string) json_encode([
                    'paths' => [
                        [
                            'distance' => 1234.56,
                            'time' => 900000,
                            'points' => ['coordinates' => [[9.43, 54.79], [9.44, 54.80]]],
                        ],
                    ],
                ]));
            },
        ));

        try {
            $client->request('GET', '/api/route?fromLat=54.323&fromLon=10.137&toLat=54.324&toLon=10.140');

            self::assertResponseIsSuccessful();
            self::assertCount(1, $requests);
            self::assertSame('POST', $requests[0]['method']);
            $body = json_decode((string) $requests[0]['body'], true);
            $conditions = array_column($body['custom_model']['priority'], 'if');
            self::assertContains('road_class == STEPS', $conditions);
            self::assertContains('in_tag_stairs_1', $conditions);
            $feature = $body['custom_model']['areas']['features'][0];
            self::assertSame('tag_stairs_1', $feature['id']);
            self::assertSame('Polygon', $feature['geometry']['type']);
        } finally {
            $this->removeTaggedNode('stairs');
        }
    }

    public function testRouteForPreferringToiletsSendsInvertedAreaRules(): void
    {
        $client = static::createClient();
        $user = $this->createUser('route-toilet-prefer@example.test', []);
        $client->loginUser($user);
        $this->removeTaggedNode('toilets');
        $this->createTaggedNode($user, 'toilets', 54.3235, 10.1385);
        static::getContainer()->get(TagPriorityManager::class)->save(
            $user,
            new SaveTagPrioritiesRequest(['toilets' => 'strongly_prefer']),
        );

        $requests = [];
        static::getContainer()->set('http_client', new MockHttpClient(
            function (string $method, string $url, array $options) use (&$requests): MockResponse {
                $requests[] = ['method' => $method, 'body' => $options['body'] ?? null];

                return new MockResponse((string) json_encode([
                    'paths' => [
                        [
                            'distance' => 1234.56,
                            'time' => 900000,
                            'points' => ['coordinates' => [[9.43, 54.79], [9.44, 54.80]]],
                        ],
                    ],
                ]));
            },
        ));

        try {
            $client->request('GET', '/api/route?fromLat=54.323&fromLon=10.137&toLat=54.324&toLon=10.140');

            self::assertResponseIsSuccessful();
            self::assertCount(1, $requests);
            self::assertSame('POST', $requests[0]['method']);
            $body = json_decode((string) $requests[0]['body'], true);
            // Toilets has no OSM encoded value; preference is expressed by
            // penalizing everything outside the tagged node's area.
            self::assertSame(
                [['if' => '!in_tag_toilets_1', 'multiply_by' => '0.5']],
                $body['custom_model']['priority'],
            );
            self::assertSame('tag_toilets_1', $body['custom_model']['areas']['features'][0]['id']);
        } finally {
            $this->removeTaggedNode('toilets');
        }
    }

    private function removeTaggedNode(string $tag): void
    {
        $entityManager = static::getContainer()->get('doctrine')->getManager();
        $node = $entityManager->getRepository(Node::class)
            ->findOneBy(['providerNodeId' => self::taggedNodeId($tag)]);

        if ($node !== null) {
            $entityManager->remove($node);
            $entityManager->flush();
        }
    }

    private function createTaggedNode(User $user, string $tag, float $latitude, float $longitude): void
    {
        $container = static::getContainer();
        $entityManager = $container->get('doctrine')->getManager();

        $node = new Node();
        $node->setNodeType($container->get(NodeTypeRepository::class)->findOrCreate('user'));
        $node->setNodeProvider($container->get(NodeProviderRepository::class)->findOrCreate('OSM'));
        $node->setProviderNodeId(self::taggedNodeId($tag));
        $node->setCreatedByUser($user);
        $node->setLatitude($latitude);
        $node->setLongitude($longitude);
        $entityManager->persist($node);

        $entityManager->persist(new NodeTag(
            $node,
            $container->get(TagRepository::class)->findOrCreate($tag, 'yes'),
            $user,
        ));
        $entityManager->flush();
    }

    private static function taggedNodeId(string $tag): string
    {
        return 'n-'.$tag.'-priority-test';
    }
}
