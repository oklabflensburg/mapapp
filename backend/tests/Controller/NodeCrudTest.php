<?php

namespace App\Tests\Controller;

use App\Entity\Node;
use App\Entity\NodeProvider;
use App\Entity\NodeType;
use App\Entity\Tag;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class NodeCrudTest extends WebTestCase
{
    use CreatesUsers;

    public function testNodeFormPagesRender(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser('admin-node-form@example.test', ['admin']), 'admin');

        // The association selects stringify the referenced entities, so the
        // tables need at least one row each.
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

        $tag = $entityManager->getRepository(Tag::class)->findOneBy([]);
        if ($tag === null) {
            $tag = new Tag();
            $tag->setKey('access');
            $tag->setValue('yes');
            $entityManager->persist($tag);
        }

        $entityManager->flush();

        $client->request('GET', '/admin/node/new');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('select[name="Node[nodeType]"]');
        // Many-to-many selects submit as Node[tags][]; match by prefix.
        self::assertSelectorExists('[name*="Node[tags]"]');

        $client->request('GET', '/admin/node');

        self::assertResponseIsSuccessful();
    }

    public function testEditPageShowsFrontendPreview(): void
    {
        $client = static::createClient();
        $admin = $this->createUser('admin-node-edit@example.test', ['admin']);
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

        $node = new Node();
        $node->setNodeType($nodeType);
        $node->setNodeProvider($nodeProvider);
        $node->setProviderNodeId('preview-'.uniqid());
        $node->setCreatedByUser($admin);
        $node->setLatitude(54.791);
        $node->setLongitude(9.437);
        $entityManager->persist($node);
        $entityManager->flush();

        $crawler = $client->request('GET', '/admin/node/'.$node->getId().'/edit');

        self::assertResponseIsSuccessful();
        $iframe = $crawler->filter('.node-edit-split-preview iframe')->first();
        self::assertCount(1, $iframe);
        $src = $iframe->attr('src');
        self::assertStringContainsString('http://localhost:5173/', $src);
        self::assertStringContainsString('lat=54.791', $src);
        self::assertStringContainsString('lon=9.437', $src);
        self::assertStringContainsString('zoom=18', $src);
    }
}
