<?php

namespace App\Tests\Controller;

use App\Entity\Node;
use App\Entity\NodeProvider;
use App\Entity\NodeTag;
use App\Entity\NodeType;
use App\Entity\Tag;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Approving a tag supersedes unapproved tags with the same key on the nodes
 * carrying it.
 */
final class TagApprovalCleanupTest extends WebTestCase
{
    use CreatesUsers;

    /** @var list<int> */
    private array $nodeIds = [];

    /** @var list<int> */
    private array $tagIds = [];

    protected function tearDown(): void
    {
        $manager = static::getContainer()->get('doctrine')->getManager();
        if (!$manager->isOpen()) {
            $manager = static::getContainer()->get('doctrine')->resetManager();
        }
        foreach ($this->tagIds as $id) {
            $tag = $manager->find(Tag::class, $id);
            if ($tag !== null) {
                $manager->remove($tag);
            }
        }
        foreach ($this->nodeIds as $id) {
            $node = $manager->find(Node::class, $id);
            if ($node !== null) {
                $manager->remove($node);
            }
        }
        $manager->flush();
        parent::tearDown();
    }

    private function createNode(string $providerNodeId): Node
    {
        $manager = static::getContainer()->get('doctrine')->getManager();

        $nodeType = $manager->getRepository(NodeType::class)->findOneBy([]);
        if ($nodeType === null) {
            $nodeType = new NodeType();
            $nodeType->setName('place');
            $manager->persist($nodeType);
        }

        $nodeProvider = $manager->getRepository(NodeProvider::class)->findOneBy([]);
        if ($nodeProvider === null) {
            $nodeProvider = new NodeProvider();
            $nodeProvider->setName('local');
            $manager->persist($nodeProvider);
        }

        $node = new Node();
        $node->setNodeType($nodeType);
        $node->setNodeProvider($nodeProvider);
        $node->setProviderNodeId($providerNodeId);
        $node->setCreatedByUser($this->createUser('tag-approval-editor@example.test', ['editor']));
        $node->setLatitude(54.791);
        $node->setLongitude(9.437);
        $manager->persist($node);
        $manager->flush();
        $this->nodeIds[] = $node->getId();

        return $node;
    }

    private function addTag(Node $node, string $key, string $value, bool $approved): Tag
    {
        $manager = static::getContainer()->get('doctrine')->getManager();

        $tag = new Tag();
        $tag->setKey($key);
        $tag->setValue($value);
        $tag->setApproved($approved);
        $manager->persist($tag);

        $nodeTag = new NodeTag($node, $tag, $node->getCreatedByUser());
        $manager->persist($nodeTag);
        $manager->flush();
        $this->tagIds[] = $tag->getId();

        return $tag;
    }

    /**
     * @return list<string> Tag key=value pairs added to the node.
     */
    private function nodeTagSummaries(Node $node): array
    {
        $summaries = [];
        foreach ($node->getNodeTags() as $nodeTag) {
            $summaries[] = $nodeTag->getTag()->getKey().'='.$nodeTag->getTag()->getValue();
        }
        sort($summaries);

        return $summaries;
    }

    public function testApprovingTagRemovesUnapprovedTagsWithSameKeyOnItsNodes(): void
    {
        $identity = bin2hex(random_bytes(8));
        $client = static::createClient();

        $node = $this->createNode('tag-approval-'.$identity);
        $approved = $this->addTag($node, 'cuisine', 'french-'.$identity, false);
        $this->addTag($node, 'access', 'yes-'.$identity, false);
        $otherNode = $this->createNode('tag-approval-other-'.$identity);
        $this->addTag($otherNode, 'cuisine', 'german-'.$identity, false);

        $client->loginUser($this->createUser('admin-tag-approval@example.test', ['admin']), 'admin');
        $crawler = $client->request('GET', '/admin/tag/'.$approved->getId().'/edit');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Save changes')->form();
        $form['Tag[approved]']->tick();
        $client->submit($form);
        self::assertResponseRedirects();

        $manager = static::getContainer()->get('doctrine')->getManager();
        $manager->clear();

        self::assertTrue($manager->find(Tag::class, $approved->getId())->isApproved());
        // The unapproved cuisine value is superseded; other keys stay.
        self::assertSame(['access=yes-'.$identity, 'cuisine=french-'.$identity], $this->nodeTagSummaries($manager->find(Node::class, $node->getId())));
        // Nodes without the approved tag keep their unapproved values.
        self::assertSame(['cuisine=german-'.$identity], $this->nodeTagSummaries($manager->find(Node::class, $otherNode->getId())));
    }

    public function testAdminTaggingNodeRemovesUnapprovedTagsWithSameKey(): void
    {
        $identity = bin2hex(random_bytes(8));

        $client = static::createClient();
        $client->loginUser($this->createUser('tag-approval-editor2@example.test', ['editor']));
        $client->jsonRequest('POST', '/api/nodes', [
            'provider' => 'local',
            'providerNodeId' => 'tag-approval-'.$identity,
            'latitude' => 54.791,
            'longitude' => 9.437,
            'tags' => ['cuisine' => 'french-'.$identity, 'access' => 'yes-'.$identity],
        ]);
        self::assertResponseStatusCodeSame(201);
        $nodeId = json_decode($client->getResponse()->getContent(), true)['id'];
        $this->nodeIds[] = $nodeId;

        $client->getCookieJar()->clear();
        static::ensureKernelShutdown();

        $client2 = static::createClient();
        $client2->loginUser($this->createUser('admin-tag-approval-api@example.test', ['admin']));
        $client2->jsonRequest('POST', '/api/nodes', [
            'provider' => 'local',
            'providerNodeId' => 'tag-approval-'.$identity,
            'latitude' => 54.791,
            'longitude' => 9.437,
            'tags' => ['cuisine' => 'italian-'.$identity],
        ]);
        self::assertResponseStatusCodeSame(200);

        $manager = static::getContainer()->get('doctrine')->getManager();
        $manager->clear();
        $node = $manager->find(Node::class, $nodeId);

        // The admin's approved cuisine value supersedes the editor's.
        self::assertSame(['access=yes-'.$identity, 'cuisine=italian-'.$identity], $this->nodeTagSummaries($node));
        foreach ($node->getNodeTags() as $nodeTag) {
            if ($nodeTag->getTag()->getKey() === 'cuisine') {
                self::assertTrue($nodeTag->getTag()->isApproved());
            }
        }
    }
}
