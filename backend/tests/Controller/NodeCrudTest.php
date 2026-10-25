<?php

namespace App\Tests\Controller;

use App\Entity\Node;
use App\Entity\NodeProvider;
use App\Entity\NodeTag;
use App\Entity\NodeType;
use App\Entity\Tag;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class NodeCrudTest extends WebTestCase
{
    use CreatesUsers;

    public function testEditPageGroupsUnapprovedTagsByKeyWithExpandableGroups(): void
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

        // Two distinct unapproved values under the same key, plus one
        // approved value that must not show up in the groups.
        $shared = new Tag();
        $shared->setKey('access');
        $shared->setValue('shared-'.bin2hex(random_bytes(8)));
        $entityManager->persist($shared);

        $other = new Tag();
        $other->setKey('access');
        $other->setValue('other-'.bin2hex(random_bytes(8)));
        $entityManager->persist($other);

        $approved = new Tag();
        $approved->setKey('access');
        $approved->setValue('approved-'.bin2hex(random_bytes(8)));
        $entityManager->persist($approved);

        // The approved value was attached before the editors proposed
        // theirs; approval lives on the row, not on the shared tag.
        $approvedNodeTag = $node->addTag($approved, $editorOne);
        $approvedNodeTag->setApproved(true);
        $entityManager->persist($approvedNodeTag);
        $entityManager->flush();

        // Insertion order differs from display order: the single-observation
        // value is added first, so the expected rows below prove the sort.
        $otherNodeTag = $node->addTag($other, $editorOne);
        $entityManager->persist($otherNodeTag);

        // Both editors added the shared tag: one value, two users.
        $sharedNodeTag = $node->addTag($shared, $editorOne);
        $entityManager->persist($sharedNodeTag);
        $entityManager->persist($node->addTag($shared, $editorTwo));
        $entityManager->flush();

        try {
            $crawler = $client->request('GET', '/admin/node/'.$node->getId().'/edit');

            self::assertResponseIsSuccessful();
            $section = $crawler->filter('.node-edit-unapproved-tags');
            self::assertCount(1, $section);
            self::assertStringNotContainsString($approved->getValue(), $section->text());

            // Grouped by key: one expandable group for 'access' with the
            // count of pending values in its summary.
            $groups = $section->filter('details.unapproved-tag-key-group');
            self::assertCount(1, $groups);
            $summary = $groups->first()->filter('summary');
            self::assertSame($shared->getKey(), $summary->filter('.unapproved-tag-key')->text());
            self::assertStringContainsString('2 pending values', $summary->filter('.unapproved-tag-count')->text());

            // One row per distinct tag, with the amount of observations
            // (how many users added that value) and no user names.
            $rows = $section->filter('tbody tr');
            self::assertCount(2, $rows);
            self::assertSame(
                [$shared->getValue(), $other->getValue()],
                $rows->each(fn ($row) => $row->children()->eq(0)->text()),
            );
            self::assertSame('2', $rows->eq(0)->children()->eq(1)->text());
            self::assertSame('1', $rows->eq(1)->children()->eq(1)->text());
            self::assertStringNotContainsString($editorOne->getName(), $section->html());
            self::assertStringNotContainsString($editorTwo->getName(), $section->html());
            self::assertSame('/admin/node-tag/'.$sharedNodeTag->getId().'/approve', $rows->eq(0)->filter('form')->attr('action'));
            self::assertSame('/admin/node-tag/'.$otherNodeTag->getId().'/approve', $rows->eq(1)->filter('form')->attr('action'));

            $client->submit($rows->eq(0)->filter('form')->form());
            self::assertResponseRedirects();

            $entityManager = static::getContainer()->get('doctrine')->getManager();
            self::assertTrue($entityManager->find(NodeTag::class, $sharedNodeTag->getId())->isApproved());

            // Approving one value supersedes the other pending value for the
            // same key: its attribution is removed from the node.
            $entityManager->clear();
            $remaining = [];
            foreach ($entityManager->find(Node::class, $node->getId())->getNodeTags() as $nodeTag) {
                $remaining[] = $nodeTag->getTag()->getValue();
            }
            sort($remaining);
            self::assertSame([$approved->getValue(), $shared->getValue(), $shared->getValue()], $remaining);

            // With no pending values left, the section shows its empty state.
            $crawler = $client->request('GET', '/admin/node/'.$node->getId().'/edit');
            self::assertResponseIsSuccessful();
            $section = $crawler->filter('.node-edit-unapproved-tags');
            self::assertCount(1, $section);
            self::assertStringContainsString('No unapproved tags on this node.', $section->text());
            self::assertCount(0, $section->filter('form'));

            // Approving requires a valid CSRF token.
            $client->request('POST', '/admin/node-tag/'.$sharedNodeTag->getId().'/approve', ['_token' => 'invalid']);
            self::assertResponseStatusCodeSame(403);
        } finally {
            $manager = static::getContainer()->get('doctrine')->getManager();
            $manager->remove($manager->find(Node::class, $node->getId()));
            $manager->remove($manager->find(Tag::class, $shared->getId()));
            $manager->remove($manager->find(Tag::class, $other->getId()));
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
