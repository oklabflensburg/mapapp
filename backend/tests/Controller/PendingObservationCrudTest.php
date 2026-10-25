<?php

namespace App\Tests\Controller;

use App\Entity\Node;
use App\Entity\NodeProvider;
use App\Entity\NodeTag;
use App\Entity\NodeType;
use App\Entity\Tag;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PendingObservationCrudTest extends WebTestCase
{
    use CreatesUsers;

    public function testPendingObservationsListNodesWithUnapprovedTagsOnly(): void
    {
        $client = static::createClient();
        $admin = $this->createUser('admin-pending@example.test', ['admin']);
        $editor = $this->createUser('editor-pending@example.test', ['editor']);
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

        $unapprovedTag = new Tag();
        $unapprovedTag->setKey('access');
        $unapprovedTag->setValue('pending-crud-'.bin2hex(random_bytes(8)));
        $entityManager->persist($unapprovedTag);

        $approvedTag = new Tag();
        $approvedTag->setKey('access');
        $approvedTag->setValue('pending-crud-approved-'.bin2hex(random_bytes(8)));
        $entityManager->persist($approvedTag);

        $nextUnapprovedTag = new Tag();
        $nextUnapprovedTag->setKey('access');
        $nextUnapprovedTag->setValue('pending-crud-next-'.bin2hex(random_bytes(8)));
        $entityManager->persist($nextUnapprovedTag);

        // Gives the first node a second key group on the edit page.
        $otherKeyTag = new Tag();
        $otherKeyTag->setKey('note');
        $otherKeyTag->setValue('pending-crud-other-'.bin2hex(random_bytes(8)));
        $entityManager->persist($otherKeyTag);

        $nodeWithPendingTag = new Node();
        $nodeWithPendingTag->setNodeType($nodeType);
        $nodeWithPendingTag->setNodeProvider($nodeProvider);
        $nodeWithPendingTag->setProviderNodeId('pending-crud-'.bin2hex(random_bytes(8)));
        $nodeWithPendingTag->setCreatedByUser($editor);
        $nodeWithPendingTag->setLatitude(54.791);
        $nodeWithPendingTag->setLongitude(9.437);
        $entityManager->persist($nodeWithPendingTag);

        $nodeWithoutPendingTag = new Node();
        $nodeWithoutPendingTag->setNodeType($nodeType);
        $nodeWithoutPendingTag->setNodeProvider($nodeProvider);
        $nodeWithoutPendingTag->setProviderNodeId('pending-crud-'.bin2hex(random_bytes(8)));
        $nodeWithoutPendingTag->setCreatedByUser($editor);
        $nodeWithoutPendingTag->setLatitude(54.792);
        $nodeWithoutPendingTag->setLongitude(9.438);
        $entityManager->persist($nodeWithoutPendingTag);

        // Created last so it is the next pending node after
        // $nodeWithPendingTag; $nodeWithoutPendingTag sits in between and
        // must be skipped by the "Next pending" navigation.
        $nextPendingNode = new Node();
        $nextPendingNode->setNodeType($nodeType);
        $nextPendingNode->setNodeProvider($nodeProvider);
        $nextPendingNode->setProviderNodeId('pending-crud-'.bin2hex(random_bytes(8)));
        $nextPendingNode->setCreatedByUser($editor);
        $nextPendingNode->setLatitude(54.793);
        $nextPendingNode->setLongitude(9.439);
        $entityManager->persist($nextPendingNode);

        // The list orders nodes by their latest unapproved tag's
        // updated_at, so $nextPendingNode (newer tag) is listed before
        // $nodeWithPendingTag (older tags) despite its higher node id.
        $unapprovedTag->setUpdatedAt(new \DateTimeImmutable('-3 hours'));
        $otherKeyTag->setUpdatedAt(new \DateTimeImmutable('-2 hours'));
        $nextUnapprovedTag->setUpdatedAt(new \DateTimeImmutable('-1 hour'));

        $entityManager->persist(new NodeTag($nodeWithPendingTag, $unapprovedTag, $editor));
        // Approval is per tag and per node: it lives on the node tag row.
        $approvedNodeTag = new NodeTag($nodeWithoutPendingTag, $approvedTag, $editor);
        $approvedNodeTag->setApproved(true);
        $entityManager->persist($approvedNodeTag);
        $entityManager->persist(new NodeTag($nodeWithPendingTag, $otherKeyTag, $editor));
        $entityManager->persist(new NodeTag($nextPendingNode, $nextUnapprovedTag, $editor));
        $entityManager->flush();

        // The request reuses this kernel and its entity manager; detach the
        // in-memory entities so the request hydrates fresh instances with
        // their persisted node tags instead of the empty in-memory ones.
        $entityManager->clear();

        try {
            $client->request('GET', '/admin/pending-observation');

            self::assertResponseIsSuccessful();
            // The node with an unapproved tag is listed with that tag.
            self::assertSelectorTextContains('table', $nodeWithPendingTag->getProviderNodeId());
            self::assertSelectorTextContains('table', (string) $unapprovedTag);
            // Nodes without unapproved tags are filtered out.
            $tableText = $client->getCrawler()->filter('table')->text();
            self::assertStringNotContainsString($nodeWithoutPendingTag->getProviderNodeId(), $tableText);
            // Nodes whose pending tags changed most recently come first.
            self::assertLessThan(
                strpos($tableText, $nodeWithPendingTag->getProviderNodeId()),
                strpos($tableText, $nextPendingNode->getProviderNodeId()),
            );
            // The list is reduced to the review-relevant columns.
            $headerText = $client->getCrawler()->filter('thead')->text();
            self::assertStringNotContainsString('Node Type', $headerText);
            self::assertStringNotContainsString('Node Provider', $headerText);
            self::assertStringNotContainsString('Latitude', $headerText);
            self::assertStringNotContainsString('Longitude', $headerText);
            // The pending edit view is a review screen: approval UI, but no
            // node edit form.
            $client->request('GET', '/admin/pending-observation/'.$nodeWithPendingTag->getId().'/edit');

            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('body', 'Unapproved tags');
            self::assertSelectorTextContains('body', $unapprovedTag->getValue());
            $bodyText = $client->getCrawler()->filter('body')->text();
            self::assertStringNotContainsString('Latitude', $bodyText);
            // It links to the node edit view instead of embedding the form.
            self::assertSelectorTextContains('body', 'Edit node');
            self::assertSelectorExists(sprintf('a[href="/admin/node/%d/edit"]', $nodeWithPendingTag->getId()));
            // The first key group is expanded by default, later ones are not.
            $groups = $client->getCrawler()->filter('details.unapproved-tag-key-group');
            self::assertSame(2, $groups->count());
            self::assertNotNull($groups->first()->attr('open'));
            self::assertNull($groups->eq(1)->attr('open'));
            // The edit view offers a button to the next pending node.
            self::assertSelectorTextContains('body', 'Next pending');
            self::assertSelectorExists(sprintf('a[href*="/admin/node/%d/next-pending"]', $nodeWithPendingTag->getId()));
            // The list is reachable from the sidebar.
            self::assertSelectorExists('a[href*="/admin/pending-observation"]');

            // The node edit view still shows the full form.
            $client->request('GET', '/admin/node/'.$nodeWithPendingTag->getId().'/edit');

            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('body', 'Latitude');

            // The button opens the edit view of the next node with
            // unapproved tags, skipping nodes without any. Clicked from the
            // pending view, the next node opens there as well.
            $client->request('GET', '/admin/node/'.$nodeWithPendingTag->getId().'/next-pending', [], [], [
                'HTTP_REFERER' => 'http://localhost/admin/pending-observation/'.$nodeWithPendingTag->getId().'/edit',
            ]);
            self::assertResponseRedirects('/admin/pending-observation/'.$nextPendingNode->getId().'/edit');

            // Clicked from a Nodes CRUD edit page, the next node opens in
            // the same section.
            $client->request('GET', '/admin/node/'.$nodeWithPendingTag->getId().'/next-pending', [], [], [
                'HTTP_REFERER' => 'http://localhost/admin/node/'.$nodeWithPendingTag->getId().'/edit',
            ]);
            self::assertResponseRedirects('/admin/node/'.$nextPendingNode->getId().'/edit');
        } finally {
            $manager = static::getContainer()->get('doctrine')->getManager();
            $manager->remove($manager->find(Node::class, $nodeWithPendingTag->getId()));
            $manager->remove($manager->find(Node::class, $nodeWithoutPendingTag->getId()));
            $manager->remove($manager->find(Node::class, $nextPendingNode->getId()));
            $manager->remove($manager->find(Tag::class, $unapprovedTag->getId()));
            $manager->remove($manager->find(Tag::class, $approvedTag->getId()));
            $manager->remove($manager->find(Tag::class, $nextUnapprovedTag->getId()));
            $manager->remove($manager->find(Tag::class, $otherKeyTag->getId()));
            $manager->flush();
        }
    }
}
