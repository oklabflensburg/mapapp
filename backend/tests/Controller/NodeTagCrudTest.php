<?php

namespace App\Tests\Controller;

use App\Entity\Node;
use App\Entity\NodeProvider;
use App\Entity\NodeTag;
use App\Entity\NodeType;
use App\Entity\Tag;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class NodeTagCrudTest extends WebTestCase
{
    use CreatesUsers;

    public function testNodeTagPagesShowUserAttribution(): void
    {
        $client = static::createClient();
        $admin = $this->createUser('admin-node-tag@example.test', ['admin']);
        $editor = $this->createUser('editor-node-tag@example.test', ['editor']);
        $client->loginUser($admin, 'admin');

        $entityManager = static::getContainer()->get('doctrine')->getManager();

        $nodeType = $entityManager->getRepository(NodeType::class)->findOneBy([]);
        if ($nodeType === null) {
            $nodeType = new NodeType();
            $nodeType->setName('place');
            $entityManager->persist($nodeType);
        }

        $nodeProvider = $entityManager->getRepository(NodeProvider::class)->findOneBy([]);
        if ($nodeProvider === null) {
            $nodeProvider = new NodeProvider();
            $nodeProvider->setName('local');
            $entityManager->persist($nodeProvider);
        }

        $tag = new Tag();
        $tag->setKey('access');
        $tag->setValue('node-tag-crud-'.bin2hex(random_bytes(8)));
        $entityManager->persist($tag);

        $node = new Node();
        $node->setNodeType($nodeType);
        $node->setNodeProvider($nodeProvider);
        $node->setProviderNodeId('node-tag-crud-'.bin2hex(random_bytes(8)));
        $node->setCreatedByUser($editor);
        $node->setLatitude(54.791);
        $node->setLongitude(9.437);
        $entityManager->persist($node);

        $nodeTag = new NodeTag($node, $tag, $editor);
        $entityManager->persist($nodeTag);
        $entityManager->flush();

        try {
            $client->request('GET', '/admin/node-tag');

            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('table', (string) $node);
            self::assertSelectorTextContains('table', (string) $tag);
            self::assertSelectorTextContains('table', $editor->getName());

            // The search box finds attributions by node, tag and user.
            $client->request('GET', '/admin/node-tag', ['query' => $node->getProviderNodeId()]);
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('table', $editor->getName());

            // The tag detail page lists where the tag was added and by whom.
            $client->request('GET', '/admin/tag/'.$tag->getId());

            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('body', (string) $nodeTag);
        } finally {
            $manager = static::getContainer()->get('doctrine')->getManager();
            $manager->remove($manager->find(Node::class, $node->getId()));
            $manager->remove($manager->find(Tag::class, $tag->getId()));
            $manager->flush();
        }
    }
}
