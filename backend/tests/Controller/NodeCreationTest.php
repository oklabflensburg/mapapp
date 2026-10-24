<?php

namespace App\Tests\Controller;

use App\Entity\Node;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class NodeCreationTest extends WebTestCase
{
    use CreatesUsers;

    private const EDITOR_EMAIL = 'editor@example.test';

    private function loginEditor(KernelBrowser $client): void
    {
        $client->loginUser($this->createUser(self::EDITOR_EMAIL, ['editor']));
    }

    /** @var list<int> */
    private array $nodeIds = [];

    protected function tearDown(): void
    {
        $manager = static::getContainer()->get('doctrine')->getManager();
        if (!$manager->isOpen()) {
            $manager = static::getContainer()->get('doctrine')->resetManager();
        }
        foreach (array_unique($this->nodeIds) as $id) {
            $node = $manager->find(Node::class, $id);
            if ($node !== null) {
                $manager->remove($node);
            }
        }
        $manager->flush();
        parent::tearDown();
    }

    private function payload(): array
    {
        return ['provider' => 'local', 'providerNodeId' => bin2hex(random_bytes(16)), 'latitude' => 54.791, 'longitude' => 9.437, 'tags' => []];
    }

    private function save(KernelBrowser $client, array $payload, int $status = 201): array
    {
        $client->jsonRequest('POST', '/api/nodes', $payload);
        self::assertResponseStatusCodeSame($status);
        $data = json_decode($client->getResponse()->getContent(), true);
        if (isset($data['id'])) {
            $this->nodeIds[] = $data['id'];
            array_push($this->nodeIds, ...$data['parentIds']);
        }

        return $data;
    }

    public function testStandaloneCreationRetryAndProviderIdentity(): void
    {
        $client = static::createClient();
        $this->loginEditor($client);
        $payload = $this->payload();
        $node = $this->save($client, $payload);
        self::assertSame('local', $node['provider']);
        self::assertSame([], $node['parentIds']);
        self::assertSame([], $node['childIds']);
        self::assertSame([], $node['parents']);
        self::assertSame([], $node['children']);
        self::assertSame($node['id'], $this->save($client, $payload, 200)['id']);
        $osm = $this->save($client, array_replace($payload, ['provider' => 'OSM']));
        self::assertNotSame($node['id'], $osm['id']);
    }

    public function testMultipleParentsChildrenAndTagUpdates(): void
    {
        $client = static::createClient();
        $this->loginEditor($client);
        $parentTags = ['name' => 'Named parent', 'access' => 'yes'];
        $parent = $this->save($client, array_replace($this->payload(), ['tags' => $parentTags]));
        $otherParent = $this->save($client, $this->payload());
        $payload = $this->payload() + ['parent' => ['id' => $parent['id']]];
        $child = $this->save($client, $payload);
        self::assertSame([$parent['id']], $child['parentIds']);
        self::assertSame([[
            'id' => $parent['id'],
            'provider' => 'local',
            'providerNodeId' => $parent['providerNodeId'],
            'name' => 'Named parent',
            'tags' => $parentTags,
        ]], $child['parents']);
        self::assertSame($child['id'], $this->save($client, $payload, 200)['id']);
        $payload['parent'] = ['id' => $otherParent['id']];
        $child = $this->save($client, $payload, 200);
        self::assertEqualsCanonicalizing([$parent['id'], $otherParent['id']], $child['parentIds']);
        self::assertEqualsCanonicalizing($child['parentIds'], array_column($child['parents'], 'id'));
        unset($payload['parent']);
        $payload['tags'] = ['name' => 'New node'];
        $edited = $this->save($client, $payload, 200);
        self::assertEqualsCanonicalizing($child['parentIds'], $edited['parentIds']);
        self::assertEqualsCanonicalizing($child['parents'], $edited['parents']);
        self::assertSame('New node', $edited['tags']['name']);
        $sibling = $this->save($client, $this->payload() + ['parent' => ['id' => $parent['id']]]);
        $client->request('GET', '/api/nodes');
        $nodes = json_decode($client->getResponse()->getContent(), true);
        $listedParent = array_values(array_filter($nodes, static fn (array $n): bool => $n['id'] === $parent['id']))[0];
        self::assertEqualsCanonicalizing([$child['id'], $sibling['id']], $listedParent['childIds']);
        self::assertEqualsCanonicalizing($listedParent['childIds'], array_column($listedParent['children'], 'id'));
        $namedChild = array_values(array_filter($listedParent['children'], static fn (array $n): bool => $n['id'] === $child['id']))[0];
        self::assertSame('New node', $namedChild['name']);
        self::assertSame(['name' => 'New node'], $namedChild['tags']);
        $listedChild = array_values(array_filter($nodes, static fn (array $n): bool => $n['id'] === $child['id']))[0];
        $namedParent = array_values(array_filter($listedChild['parents'], static fn (array $n): bool => $n['id'] === $parent['id']))[0];
        self::assertSame($parentTags, $namedParent['tags']);
    }

    public function testOsmReferenceCanBecomeEditedNodeWithoutDuplication(): void
    {
        $client = static::createClient();
        $this->loginEditor($client);
        $reference = ['providerNodeId' => 'n-test-'.bin2hex(random_bytes(8)), 'latitude' => 54.79, 'longitude' => 9.43];
        $child = $this->save($client, $this->payload() + ['parent' => $reference]);
        $parentId = $child['parentIds'][0];
        self::assertSame([[
            'id' => $parentId,
            'provider' => 'OSM',
            'providerNodeId' => $reference['providerNodeId'],
            'name' => null,
            'tags' => [],
        ]], $child['parents']);
        $client->request('GET', '/api/nodes');
        $nodes = json_decode($client->getResponse()->getContent(), true);
        self::assertNotContains($parentId, array_column($nodes, 'id'));
        $listedChild = array_values(array_filter($nodes, static fn (array $n): bool => $n['id'] === $child['id']))[0];
        self::assertSame($child['parents'], $listedChild['parents']);
        $secondChild = $this->save($client, $this->payload() + ['parent' => $reference]);
        self::assertSame([$parentId], $secondChild['parentIds']);
        $edited = $this->save($client, $reference + ['tags' => ['name' => 'Edited OSM parent']], 200);
        self::assertSame($parentId, $edited['id']);
        self::assertEqualsCanonicalizing([$child['id'], $secondChild['id']], $edited['childIds']);
        $client->request('GET', '/api/nodes');
        $nodes = json_decode($client->getResponse()->getContent(), true);
        $listedChild = array_values(array_filter($nodes, static fn (array $n): bool => $n['id'] === $child['id']))[0];
        self::assertSame('Edited OSM parent', $listedChild['parents'][0]['name']);
        self::assertSame(['name' => 'Edited OSM parent'], $listedChild['parents'][0]['tags']);
    }

    public function testRejectsInvalidParentSelfRelationAndCoordinates(): void
    {
        $client = static::createClient();
        $this->loginEditor($client);
        $payload = $this->payload();
        $node = $this->save($client, $payload);
        $this->save($client, $payload + ['parent' => ['id' => $node['id']]], 422);
        $this->save($client, $this->payload() + ['parent' => ['id' => 2147483647]], 422);
        $this->save($client, $this->payload() + ['parent' => []], 422);
        $this->save($client, $this->payload() + ['parent' => ['providerNodeId' => 'n1', 'latitude' => 91, 'longitude' => 0]], 422);
        $this->save($client, array_replace($this->payload(), ['longitude' => 181]), 422);
        $client->request('GET', '/api/nodes');
        self::assertResponseIsSuccessful();
        $nodes = json_decode($client->getResponse()->getContent(), true);
        $listed = array_values(array_filter($nodes, static fn (array $n): bool => $n['id'] === $node['id']))[0];
        self::assertSame([], $listed['parentIds']);
    }

    public function testDeletingParentRemovesLinksButPreservesChild(): void
    {
        $client = static::createClient();
        $this->loginEditor($client);
        $parent = $this->save($client, $this->payload());
        $child = $this->save($client, $this->payload() + ['parent' => ['id' => $parent['id']]]);
        $manager = static::getContainer()->get('doctrine')->getManager();
        $manager->remove($manager->find(Node::class, $parent['id']));
        $manager->flush();

        $client->request('GET', '/api/nodes');
        self::assertResponseIsSuccessful();
        $nodes = json_decode($client->getResponse()->getContent(), true);
        self::assertNotContains($parent['id'], array_column($nodes, 'id'));
        $listedChild = array_values(array_filter($nodes, static fn (array $n): bool => $n['id'] === $child['id']))[0];
        self::assertSame([], $listedChild['parentIds']);
        self::assertSame([], $listedChild['parents']);
    }
}
