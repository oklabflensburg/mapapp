<?php

namespace App\Tests\Controller;

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
}
