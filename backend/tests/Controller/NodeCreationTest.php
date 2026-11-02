<?php

namespace App\Tests\Controller;

use App\Entity\Node;
use App\Entity\NodeTag;
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

    public function testSingleParentMultipleChildrenAndTagUpdates(): void
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
            'latitude' => 54.791,
            'longitude' => 9.437,
        ]], $child['parents']);
        self::assertSame($child['id'], $this->save($client, $payload, 200)['id']);
        $payload['parent'] = ['id' => $otherParent['id']];
        $this->save($client, $payload, 422);
        self::assertSame([$parent['id']], $child['parentIds']);
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
            'latitude' => 54.79,
            'longitude' => 9.43,
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

    public function testSelectingChildAddsSiblingAndCannotNestExistingParent(): void
    {
        $client = static::createClient();
        $this->loginEditor($client);
        $parentPayload = $this->payload();
        $parent = $this->save($client, $parentPayload);
        $child = $this->save($client, $this->payload() + ['parent' => ['id' => $parent['id']]]);
        $sibling = $this->save($client, $this->payload() + ['parent' => ['id' => $child['id']]]);
        self::assertSame([$parent['id']], $sibling['parentIds']);
        self::assertSame([], $sibling['childIds']);
        $other = $this->save($client, $this->payload());
        $this->save($client, $parentPayload + ['parent' => ['id' => $other['id']]], 422);
        $this->save($client, $this->payload() + ['parent' => ['id' => $sibling['id']]]);
        $this->save($client, array_replace($this->payload(), ['provider' => 'OSM', 'parent' => ['id' => $parent['id']]]), 422);
    }

    public function testMultipleUsersCanAddTheSameTagToTheSameNode(): void
    {
        $client = static::createClient();
        $value = 'shared-tag-'.bin2hex(random_bytes(8));
        $editorOne = $this->createUser('editor-one@example.test', ['editor']);
        $editorTwo = $this->createUser('editor-two@example.test', ['editor']);

        $client->loginUser($editorOne);
        $payload = array_replace($this->payload(), ['tags' => ['name' => $value]]);
        $node = $this->save($client, $payload);

        $client->loginUser($editorTwo);
        $resaved = $this->save($client, $payload, 200);
        self::assertSame($node['id'], $resaved['id']);
        self::assertSame(['name' => $value], $resaved['tags']);

        $entityManager = static::getContainer()->get('doctrine')->getManager();
        $nodeTags = $entityManager->getRepository(NodeTag::class)->findBy(['node' => $node['id']]);
        self::assertCount(2, $nodeTags);
        self::assertEqualsCanonicalizing(
            [$editorOne->getId(), $editorTwo->getId()],
            array_map(static fn (NodeTag $nodeTag): int => $nodeTag->getCreatedByUser()->getId(), $nodeTags),
        );

        // The second editor removing the tag only drops their own attribution.
        $this->save($client, array_replace($payload, ['tags' => []]), 200);
        $nodeTags = $entityManager->getRepository(NodeTag::class)->findBy(['node' => $node['id']]);
        self::assertCount(1, $nodeTags);
        self::assertSame($editorOne->getId(), $nodeTags[0]->getCreatedByUser()->getId());
    }

    public function testNewTagsStartUnapprovedForEditorAndApprovedForAdmin(): void
    {
        $editorValue = 'editor-tag-'.bin2hex(random_bytes(8));
        $client = static::createClient();
        $this->loginEditor($client);
        $editorNode = $this->save($client, array_replace($this->payload(), ['tags' => ['name' => $editorValue]]));
        self::assertSame(['name' => $editorValue], $editorNode['observations']);

        $adminValue = 'admin-tag-'.bin2hex(random_bytes(8));
        $client->loginUser($this->createUser('admin-tags@example.test', ['admin']));
        $adminNode = $this->save($client, array_replace($this->payload(), ['tags' => ['name' => $adminValue]]));
        self::assertSame([], $adminNode['observations']);

        // Approval is per tag and per node: it lives on the node tag rows,
        // not on the shared tag.
        $entityManager = static::getContainer()->get('doctrine')->getManager();
        $repository = $entityManager->getRepository(NodeTag::class);
        self::assertFalse($repository->findBy(['node' => $editorNode['id']])[0]->isApproved());
        self::assertTrue($repository->findBy(['node' => $adminNode['id']])[0]->isApproved());
    }

    public function testCustomNodesRequireIndependentApprovalInTheObservationQueue(): void
    {
        $client = static::createClient();
        $this->loginEditor($client);
        $payload = $this->payload();
        $node = $this->save($client, $payload);
        $tagged = $this->save($client, array_replace($this->payload(), ['tags' => ['name' => 'Pending node']]));
        $manager = static::getContainer()->get('doctrine')->getManager();
        self::assertFalse($manager->find(Node::class, $node['id'])->isApproved());
        $tag = $manager->getRepository(NodeTag::class)->findOneBy(['node' => $tagged['id']]);
        $tag->setApproved(true);
        $manager->flush();

        // Authors can see their own submissions, even without tags.
        $client->request('GET', '/api/nodes');
        self::assertContains($node['id'], array_column(json_decode($client->getResponse()->getContent(), true), 'id'));
        $client->getCookieJar()->clear();
        $client->request('GET', '/api/nodes');
        $ids = array_column(json_decode($client->getResponse()->getContent(), true), 'id');
        self::assertNotContains($node['id'], $ids);
        // Approving a tag does not implicitly approve its node.
        self::assertNotContains($tagged['id'], $ids);

        $client->loginUser($this->createUser('node-review-admin@example.test', ['admin']), 'admin');
        $client->request('GET', '/admin/pending-observations');
        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('table', $payload['providerNodeId']);
        self::assertSelectorTextContains('table', $tagged['providerNodeId']);
        self::assertSelectorTextContains('table', 'New node awaiting approval');

        $client->request('GET', '/admin/node/'.$node['id'].'/next-pending');
        self::assertResponseRedirects('/admin/pending-observation/'.$tagged['id'].'/edit');

        $client->request('GET', '/admin/pending-observation/'.$node['id'].'/edit');
        self::assertResponseIsSuccessful();
        $form = $client->getCrawler()->selectButton('Approve node')->form();
        $client->request('POST', '/admin/node/'.$node['id'].'/approve', ['_token' => 'invalid']);
        self::assertResponseStatusCodeSame(403);
        $client->submit($form);
        self::assertResponseRedirects('/admin/pending-observation/'.$node['id'].'/edit');
        $client->followRedirect();
        self::assertSelectorNotExists('.pending-node-approval');
        $client->request('GET', '/admin/pending-observations');
        $client->followRedirect();
        self::assertStringNotContainsString($payload['providerNodeId'], $client->getCrawler()->filter('table')->text());

        // Approval publishes a tagless node; pending tags stay independent.
        $client->getCookieJar()->clear();
        $client->request('GET', '/api/nodes');
        $ids = array_column(json_decode($client->getResponse()->getContent(), true), 'id');
        self::assertContains($node['id'], $ids);
        self::assertNotContains($tagged['id'], $ids);

        // Resaving does not reset node approval.
        $this->loginEditor($client);
        $this->save($client, $payload, 200);
        self::assertTrue(static::getContainer()->get('doctrine')->getManager()->find(Node::class, $node['id'])->isApproved());
        $client->loginUser($this->createUser(self::EDITOR_EMAIL, ['editor']), 'admin');
        $client->request('POST', '/admin/node/'.$tagged['id'].'/approve', ['_token' => 'invalid']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminReviewShowsAllCustomTagsOfANewNode(): void
    {
        $client = static::createClient();
        $this->loginEditor($client);
        $name = 'New place '.bin2hex(random_bytes(8));
        $tags = ['name' => $name, 'custom_access_note' => '<step-free> & open'];
        $node = $this->save($client, array_replace($this->payload(), ['tags' => $tags]));
        $manager = static::getContainer()->get('doctrine')->getManager();
        foreach ($manager->getRepository(NodeTag::class)->findBy(['node' => $node['id']]) as $nodeTag) {
            if ($nodeTag->getTag()->getKey() === 'name') {
                $nodeTag->setApproved(true);
            }
        }
        $manager->flush();

        $client->loginUser($this->createUser('custom-tags-review-admin@example.test', ['admin']), 'admin');
        $client->request('GET', '/admin/pending-observations');
        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('table', 'name='.$name);
        self::assertSelectorTextContains('table', 'custom_access_note=<step-free> & open');

        foreach (['pending-observation', 'node'] as $screen) {
            $client->request('GET', '/admin/'.$screen.'/'.$node['id'].'/edit');
            self::assertResponseIsSuccessful();
            self::assertSelectorCount(2, '.node-custom-tags tbody tr');
            self::assertSelectorTextContains('.node-custom-tags', 'name');
            self::assertSelectorTextContains('.node-custom-tags', $name);
            self::assertSelectorTextContains('.node-custom-tags', 'custom_access_note');
            self::assertSelectorTextContains('.node-custom-tags', '<step-free> & open');
            self::assertSelectorNotExists('.node-custom-tags step-free');
            self::assertSelectorExists('.pending-node-approval');
            self::assertSelectorTextContains('.node-edit-unapproved-tags', 'custom_access_note');
        }
    }

    public function testPendingChildrenAreHiddenFromPublishedParentResponses(): void
    {
        $client = static::createClient();
        $admin = $this->createUser('node-parent-admin@example.test', ['admin']);
        $client->loginUser($admin);
        $parent = $this->save($client, $this->payload());
        self::assertTrue(static::getContainer()->get('doctrine')->getManager()->find(Node::class, $parent['id'])->isApproved());
        $this->loginEditor($client);
        $child = $this->save($client, $this->payload() + ['parent' => ['id' => $parent['id']]]);
        $client->getCookieJar()->clear();
        $client->request('GET', '/api/nodes');
        $nodes = json_decode($client->getResponse()->getContent(), true);
        self::assertNotContains($child['id'], array_column($nodes, 'id'));
        $listed = array_values(array_filter($nodes, static fn (array $node): bool => $node['id'] === $parent['id']))[0];
        self::assertSame([], $listed['childIds']);
        self::assertSame([], $listed['children']);
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
