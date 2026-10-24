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

    public function testEditPageListsUnapprovedTagsGroupedByTagWithApproveAction(): void
    {
        $client = static::createClient();
        $admin = $this->createUser('admin-node-unapproved@example.test', ['admin']);
        $editorOne = $this->createUser('editor-node-unapproved-one@example.test', ['editor']);
        $editorTwo = $this->createUser('editor-node-unapproved-two@example.test', ['editor']);
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
        $node->setProviderNodeId('unapproved-tags-'.bin2hex(random_bytes(8)));
        $node->setCreatedByUser($admin);
        $node->setLatitude(54.791);
        $node->setLongitude(9.437);
        $entityManager->persist($node);

        $unapproved = new Tag();
        $unapproved->setKey('access');
        $unapproved->setValue('unapproved-'.bin2hex(random_bytes(8)));
        $unapproved->setApproved(false);
        $entityManager->persist($unapproved);

        $approved = new Tag();
        $approved->setKey('access');
        $approved->setValue('approved-'.bin2hex(random_bytes(8)));
        $approved->setApproved(true);
        $entityManager->persist($approved);

        // Both editors added the same unapproved tag: one group, two users.
        $entityManager->persist($node->addTag($unapproved, $editorOne));
        $entityManager->persist($node->addTag($unapproved, $editorTwo));
        $entityManager->persist($node->addTag($approved, $editorOne));
        $entityManager->flush();

        try {
            $crawler = $client->request('GET', '/admin/node/'.$node->getId().'/edit');

            self::assertResponseIsSuccessful();
            $section = $crawler->filter('.node-edit-unapproved-tags');
            self::assertCount(1, $section);
            $sectionText = $section->text();
            self::assertStringContainsString($unapproved->getKey(), $sectionText);
            self::assertStringContainsString($unapproved->getValue(), $sectionText);
            self::assertStringContainsString($editorOne->getName(), $sectionText);
            self::assertStringContainsString($editorTwo->getName(), $sectionText);
            self::assertStringNotContainsString($approved->getValue(), $sectionText);
            // Grouped by tag: one table row with Key, Value and Users columns.
            $rows = $section->filter('tbody tr');
            self::assertCount(1, $rows);
            self::assertSame([$unapproved->getKey(), $unapproved->getValue()], array_slice($rows->children()->each(fn ($cell) => $cell->text()), 0, 2));
            self::assertSame(
                $editorOne->getName().', '.$editorTwo->getName(),
                $rows->children()->eq(2)->text(),
            );
            self::assertSame('/admin/tag/'.$unapproved->getId().'/approve', $rows->filter('form')->attr('action'));
            $form = $section->filter('form')->form();

            $client->submit($form);
            self::assertResponseRedirects();

            $entityManager = static::getContainer()->get('doctrine')->getManager();
            self::assertTrue($entityManager->find(Tag::class, $unapproved->getId())->isApproved());

            // The approved tag moves the section to its empty state.
            $crawler = $client->request('GET', '/admin/node/'.$node->getId().'/edit');
            self::assertResponseIsSuccessful();
            $section = $crawler->filter('.node-edit-unapproved-tags');
            self::assertCount(1, $section);
            self::assertStringContainsString('No unapproved tags on this node.', $section->text());
            self::assertCount(0, $section->filter('form'));

            // Approving requires a valid CSRF token.
            $client->request('POST', '/admin/tag/'.$approved->getId().'/approve', ['_token' => 'invalid']);
            self::assertResponseStatusCodeSame(403);
        } finally {
            $manager = static::getContainer()->get('doctrine')->getManager();
            $manager->remove($manager->find(Node::class, $node->getId()));
            $manager->remove($manager->find(Tag::class, $unapproved->getId()));
            $manager->remove($manager->find(Tag::class, $approved->getId()));
            $manager->flush();
        }
    }

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
        // Many-to-many selects submit as Node[nodeTags][]; match by prefix.
        self::assertSelectorExists('[name*="Node[nodeTags]"]');

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
