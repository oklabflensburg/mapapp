<?php

namespace App\Tests\Controller;

use App\Entity\Node;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class NodeControllerTest extends WebTestCase
{
    use CreatesUsers;

    private const EDITOR_EMAIL = 'editor@example.test';

    private function loginEditor(KernelBrowser $client): void
    {
        $client->loginUser($this->createUser(self::EDITOR_EMAIL, ['editor']));
    }

    private const PROVIDER_NODE_ID = 'n26013095';

    private function removeNodeUnderTest(): void
    {
        $entityManager = static::getContainer()->get('doctrine')->getManager();
        $node = $entityManager->getRepository(Node::class)
            ->findOneBy(['providerNodeId' => self::PROVIDER_NODE_ID]);

        if ($node !== null) {
            $entityManager->remove($node);
            $entityManager->flush();
        }
    }

    public function testCreateUserNode(): void
    {
        $client = static::createClient();
        $this->loginEditor($client);
        $this->removeNodeUnderTest();
        $client->request('POST', '/api/nodes', [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode([
            'providerNodeId' => self::PROVIDER_NODE_ID,
            'latitude' => 54.791,
            'longitude' => 9.437,
            'tags' => ['access' => 'yes', 'note' => 'step-free entrance'],
        ]));

        self::assertResponseIsSuccessful();
        self::assertSame(201, $client->getResponse()->getStatusCode());

        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame(self::PROVIDER_NODE_ID, $data['providerNodeId']);
        self::assertSame(54.791, $data['latitude']);
        self::assertSame(9.437, $data['longitude']);
        self::assertSame(['access' => 'yes', 'note' => 'step-free entrance'], $data['tags']);
    }

    public function testSavingSameNodeAgainUpdatesItInsteadOfDuplicating(): void
    {
        $client = static::createClient();
        $this->loginEditor($client);
        $this->removeNodeUnderTest();
        $payload = static fn (array $tags): string => (string) json_encode([
            'providerNodeId' => self::PROVIDER_NODE_ID,
            'latitude' => 54.791,
            'longitude' => 9.437,
            'tags' => $tags,
        ]);

        $client->request('POST', '/api/nodes', [], [], ['CONTENT_TYPE' => 'application/json'], $payload(['access' => 'yes']));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $first = json_decode((string) $client->getResponse()->getContent(), true);

        $client->request('POST', '/api/nodes', [], [], ['CONTENT_TYPE' => 'application/json'], $payload(['access' => 'no', 'note' => 'remodelled']));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $second = json_decode((string) $client->getResponse()->getContent(), true);

        self::assertSame($first['id'], $second['id']);
        self::assertSame(['access' => 'no', 'note' => 'remodelled'], $second['tags']);

        $client->request('GET', '/api/nodes');
        self::assertResponseIsSuccessful();

        $list = json_decode((string) $client->getResponse()->getContent(), true);
        $matching = array_values(array_filter(
            $list,
            static fn (array $node): bool => $node['providerNodeId'] === self::PROVIDER_NODE_ID,
        ));
        self::assertCount(1, $matching);
    }

    public function testRejectsOutOfRangeLatitude(): void
    {
        $client = static::createClient();
        $this->loginEditor($client);
        $this->removeNodeUnderTest();
        $client->request('POST', '/api/nodes', [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode([
            'providerNodeId' => self::PROVIDER_NODE_ID,
            'latitude' => 200.0,
            'longitude' => 9.437,
            'tags' => [],
        ]));

        self::assertSame(422, $client->getResponse()->getStatusCode());
    }
}
