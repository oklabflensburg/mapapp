<?php

namespace App\Tests\Controller;

use App\Entity\Node;
use App\Entity\NodeGroup;
use App\Entity\NodeGroupTag;
use App\Entity\NodeProvider;
use App\Entity\NodeTag;
use App\Entity\NodeType;
use App\Entity\Tag;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The combined review queue lists nodes and areas with unapproved tags in
 * one table.
 */
final class PendingObservationListTest extends WebTestCase
{
    use CreatesUsers;

    public function testNodesAndAreasShareOnePendingObservationsList(): void
    {
        $client = static::createClient();
        $admin = $this->createUser('admin-pending-list@example.test', ['admin']);
        $editor = $this->createUser('editor-pending-list@example.test', ['editor']);
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

        $identity = bin2hex(random_bytes(8));
        $nodeTag = new Tag();
        $nodeTag->setKey('note');
        $nodeTag->setValue('pending-list-node-'.$identity);
        $entityManager->persist($nodeTag);

        $node = new Node();
        $node->setNodeType($nodeType);
        $node->setNodeProvider($nodeProvider);
        $node->setProviderNodeId('pending-list-node-'.$identity);
        $node->setCreatedByUser($editor);
        $node->setLatitude(54.791);
        $node->setLongitude(9.437);
        $entityManager->persist($node);
        $entityManager->persist(new NodeTag($node, $nodeTag, $editor));

        $areaTag = new Tag();
        $areaTag->setKey('surface');
        $areaTag->setValue('pending-list-area-'.$identity);
        $entityManager->persist($areaTag);

        $area = new NodeGroup();
        $area->setName('Pending list area '.$identity);
        $area->setCreatedByUser($editor);
        $entityManager->persist($area);
        $entityManager->persist(new NodeGroupTag($area, $areaTag, $editor));

        $entityManager->flush();

        try {
            // The bare route carries no EasyAdmin context, which the layout
            // template needs, so it re-enters through the admin route.
            $client->request('GET', '/admin/pending-observations');
            self::assertResponseRedirects('/admin?routeName=app_admin_pending_observations');
            $client->followRedirect();

            self::assertResponseIsSuccessful();
            $tableText = $client->getCrawler()->filter('table')->text();
            // Both kinds appear in the same list, typed and with review links.
            self::assertSelectorTextContains('table', $node->getProviderNodeId());
            self::assertSelectorTextContains('table', $area->getName());
            self::assertSelectorTextContains('table', (string) $nodeTag);
            self::assertSelectorTextContains('table', (string) $areaTag);
            self::assertSelectorExists(sprintf('a[href*="/admin/pending-observation/%d/edit"]', $node->getId()));
            self::assertSelectorExists(sprintf('a[href*="/admin/pending-relation-observation/%d/edit"]', $area->getId()));
            self::assertSelectorTextContains('table', 'Node');
            self::assertSelectorTextContains('table', 'Area');
            // The list is reachable from the sidebar.
            self::assertSelectorExists('a[href*="routeName=app_admin_pending_observations"]');
        } finally {
            $manager = static::getContainer()->get('doctrine')->getManager();
            $manager->clear();
            $manager->remove($manager->find(NodeGroup::class, $area->getId()));
            $manager->remove($manager->find(Node::class, $node->getId()));
            $manager->remove($manager->find(Tag::class, $nodeTag->getId()));
            $manager->remove($manager->find(Tag::class, $areaTag->getId()));
            $manager->flush();
        }
    }
}
