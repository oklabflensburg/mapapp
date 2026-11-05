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

    /** @return array{key: string, value: string} */
    private static function tag(string $key, string $value): array
    {
        return ['key' => $key, 'value' => $value];
    }

    /** @param list<array{key: string, value: string}> $tags */
    private static function tagValue(array $tags, string $key): ?string
    {
        foreach ($tags as $tag) {
            if ($tag['key'] === $key) {
                return $tag['value'];
            }
        }

        return null;
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
        $parentTags = [self::tag('name', 'Named parent'), self::tag('access', 'yes')];
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
        $payload['tags'] = [self::tag('name', 'New node')];
        $edited = $this->save($client, $payload, 200);
        self::assertEqualsCanonicalizing($child['parentIds'], $edited['parentIds']);
        self::assertEqualsCanonicalizing($child['parents'], $edited['parents']);
        self::assertSame('New node', self::tagValue($edited['tags'], 'name'));
        $sibling = $this->save($client, $this->payload() + ['parent' => ['id' => $parent['id']]]);
        $client->request('GET', '/api/nodes');
        $nodes = json_decode($client->getResponse()->getContent(), true);
        $listedParent = array_values(array_filter($nodes, static fn (array $n): bool => $n['id'] === $parent['id']))[0];
        self::assertEqualsCanonicalizing([$child['id'], $sibling['id']], $listedParent['childIds']);
        self::assertEqualsCanonicalizing($listedParent['childIds'], array_column($listedParent['children'], 'id'));
        $namedChild = array_values(array_filter($listedParent['children'], static fn (array $n): bool => $n['id'] === $child['id']))[0];
        self::assertSame('New node', $namedChild['name']);
        self::assertSame([self::tag('name', 'New node')], $namedChild['tags']);
        $listedChild = array_values(array_filter($nodes, static fn (array $n): bool => $n['id'] === $child['id']))[0];
        $namedParent = array_values(array_filter($listedChild['parents'], static fn (array $n): bool => $n['id'] === $parent['id']))[0];
        self::assertEqualsCanonicalizing($parentTags, $namedParent['tags']);
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
        $edited = $this->save($client, $reference + ['tags' => [self::tag('name', 'Edited OSM parent')]], 200);
        self::assertSame($parentId, $edited['id']);
        self::assertEqualsCanonicalizing([$child['id'], $secondChild['id']], $edited['childIds']);
        $client->request('GET', '/api/nodes');
        $nodes = json_decode($client->getResponse()->getContent(), true);
        $listedChild = array_values(array_filter($nodes, static fn (array $n): bool => $n['id'] === $child['id']))[0];
        self::assertSame('Edited OSM parent', $listedChild['parents'][0]['name']);
        self::assertSame([self::tag('name', 'Edited OSM parent')], $listedChild['parents'][0]['tags']);
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
        $payload = array_replace($this->payload(), ['tags' => [self::tag('name', $value)]]);
        $node = $this->save($client, $payload);

        $client->loginUser($editorTwo);
        $resaved = $this->save($client, $payload, 200);
        self::assertSame($node['id'], $resaved['id']);
        self::assertSame([self::tag('name', $value)], $resaved['tags']);

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
        $editorNode = $this->save($client, array_replace($this->payload(), ['tags' => [self::tag('name', $editorValue)]]));
        self::assertFalse($editorNode['approved']);
        self::assertSame([self::tag('name', $editorValue)], $editorNode['observations']);

        $adminValue = 'admin-tag-'.bin2hex(random_bytes(8));
        $client->loginUser($this->createUser('admin-tags@example.test', ['admin']));
        $adminNode = $this->save($client, array_replace($this->payload(), ['tags' => [self::tag('name', $adminValue)]]));
        self::assertTrue($adminNode['approved']);
        self::assertSame([], $adminNode['observations']);

        // Approval is per tag and per node: it lives on the node tag rows,
        // not on the shared tag.
        $entityManager = static::getContainer()->get('doctrine')->getManager();
        $repository = $entityManager->getRepository(NodeTag::class);
        self::assertFalse($repository->findBy(['node' => $editorNode['id']])[0]->isApproved());
        self::assertTrue($repository->findBy(['node' => $adminNode['id']])[0]->isApproved());
    }

    public function testApprovingATagPublishesItsNode(): void
    {
        $client = static::createClient();
        $this->loginEditor($client);
        $payload = $this->payload();
        $node = $this->save($client, $payload);
        $taggedPayload = array_replace($this->payload(), ['tags' => [self::tag('name', 'Pending node')]]);
        $tagged = $this->save($client, $taggedPayload);
        $manager = static::getContainer()->get('doctrine')->getManager();
        // Custom nodes stay pending until one of their tags is approved.
        self::assertFalse($manager->find(Node::class, $node['id'])->isApproved());
        self::assertFalse($manager->find(Node::class, $tagged['id'])->isApproved());

        // Authors can see their own submissions, even without tags.
        $client->request('GET', '/api/nodes');
        $listed = array_column(json_decode($client->getResponse()->getContent(), true), null, 'id');
        self::assertFalse($listed[$node['id']]['approved']);
        self::assertFalse($listed[$tagged['id']]['approved']);
        // Another editor cannot see either pending custom node.
        $client->loginUser($this->createUser('node-visibility-other@example.test', ['editor']));
        $client->request('GET', '/api/nodes');
        $ids = array_column(json_decode($client->getResponse()->getContent(), true), 'id');
        self::assertNotContains($node['id'], $ids);
        self::assertNotContains($tagged['id'], $ids);
        $client->getCookieJar()->clear();
        $client->request('GET', '/api/nodes');
        $ids = array_column(json_decode($client->getResponse()->getContent(), true), 'id');
        self::assertNotContains($node['id'], $ids);
        self::assertNotContains($tagged['id'], $ids);

        // The queue lists only nodes with unapproved tags, not tagless ones.
        $client->loginUser($this->createUser('node-review-admin@example.test', ['admin']), 'admin');
        $client->request('GET', '/admin/pending-observations');
        $client->followRedirect();
        self::assertResponseIsSuccessful();
        $tableText = $client->getCrawler()->filter('table')->text();
        self::assertStringNotContainsString($payload['providerNodeId'], $tableText);
        self::assertSelectorTextContains('table', $tagged['providerNodeId']);

        // Approving the tag through the review screen approves its node.
        $nodeTagId = $manager->getRepository(NodeTag::class)->findOneBy(['node' => $tagged['id']])->getId();
        $crawler = $client->request('GET', '/admin/node/'.$tagged['id'].'/edit');
        $form = $crawler->filter('form[action="/admin/node-tag/'.$nodeTagId.'/approve"]')->form();
        $client->submit($form);
        self::assertResponseRedirects();
        $manager->clear();
        self::assertTrue($manager->find(Node::class, $tagged['id'])->isApproved());

        // The published node leaves the queue.
        $client->request('GET', '/admin/pending-observations');
        $client->followRedirect();
        self::assertStringNotContainsString($tagged['providerNodeId'], $client->getCrawler()->filter('table')->text());

        // The tagless node stays pending; the tagged one is public.
        $client->getCookieJar()->clear();
        $client->request('GET', '/api/nodes');
        $ids = array_column(json_decode($client->getResponse()->getContent(), true), 'id');
        self::assertNotContains($node['id'], $ids);
        self::assertContains($tagged['id'], $ids);
        $listed = array_column(json_decode($client->getResponse()->getContent(), true), null, 'id');
        self::assertTrue($listed[$tagged['id']]['approved']);

        // Resaving does not reset the derived node approval.
        $this->loginEditor($client);
        $this->save($client, $taggedPayload, 200);
        self::assertTrue(static::getContainer()->get('doctrine')->getManager()->find(Node::class, $tagged['id'])->isApproved());
    }

    public function testAdminReviewShowsAllCustomTagsOfANewNode(): void
    {
        $client = static::createClient();
        $this->loginEditor($client);
        $name = 'New place '.bin2hex(random_bytes(8));
        $tags = [self::tag('name', $name), self::tag('custom_access_note', '<step-free> & open')];
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
        // The queue lists the node's unapproved tags; approved ones are gone.
        $tableText = $client->getCrawler()->filter('table')->text();
        self::assertStringContainsString('custom_access_note=<step-free> & open', $tableText);
        self::assertStringNotContainsString('name='.$name, $tableText);

        foreach (['pending-observation', 'node'] as $screen) {
            $client->request('GET', '/admin/'.$screen.'/'.$node['id'].'/edit');
            self::assertResponseIsSuccessful();
            self::assertSelectorCount(2, '.node-custom-tags tbody tr');
            self::assertSelectorTextContains('.node-custom-tags', 'name');
            self::assertSelectorTextContains('.node-custom-tags', $name);
            self::assertSelectorTextContains('.node-custom-tags', 'custom_access_note');
            self::assertSelectorTextContains('.node-custom-tags', '<step-free> & open');
            self::assertSelectorNotExists('.node-custom-tags step-free');
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
