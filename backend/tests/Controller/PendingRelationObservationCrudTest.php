<?php

namespace App\Tests\Controller;

use App\Entity\Node;
use App\Entity\NodeGroup;
use App\Entity\NodeGroupTag;
use App\Entity\NodeProvider;
use App\Entity\NodeType;
use App\Entity\Tag;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PendingRelationObservationCrudTest extends WebTestCase
{
    use CreatesUsers;

    public function testPendingRelationObservationsListRelationsWithUnapprovedTagsOnly(): void
    {
        $client = static::createClient();
        $admin = $this->createUser('admin-pending-relation@example.test', ['admin']);
        $editor = $this->createUser('editor-pending-relation@example.test', ['editor']);
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

        $createNode = function () use ($entityManager, $nodeType, $nodeProvider, $editor): Node {
            $node = new Node();
            $node->setNodeType($nodeType);
            $node->setNodeProvider($nodeProvider);
            $node->setProviderNodeId('pending-relation-'.bin2hex(random_bytes(8)));
            $node->setCreatedByUser($editor);
            $node->setLatitude(54.791);
            $node->setLongitude(9.437);
            $entityManager->persist($node);

            return $node;
        };

        $createTag = static function (string $key, string $value) use ($entityManager): Tag {
            $tag = new Tag();
            $tag->setKey($key);
            $tag->setValue($value);
            $entityManager->persist($tag);

            return $tag;
        };

        $unapprovedTag = $createTag('surface', 'pending-relation-'.bin2hex(random_bytes(8)));
        $approvedTag = $createTag('surface', 'pending-relation-approved-'.bin2hex(random_bytes(8)));
        $otherKeyTag = $createTag('note', 'pending-relation-other-'.bin2hex(random_bytes(8)));
        $nextUnapprovedTag = $createTag('surface', 'pending-relation-next-'.bin2hex(random_bytes(8)));

        // Approval is per tag and per relation, so it is set on the row that
        // links the tag to the relation.
        $createGroup = function (string $name, array $nodes, array $unapprovedTags, array $approvedTags = []) use ($entityManager, $editor): NodeGroup {
            $group = new NodeGroup();
            $group->setName($name);
            $group->setCreatedByUser($editor);
            $entityManager->persist($group);
            foreach ($nodes as $node) {
                $entityManager->persist($group->addMember($node));
            }
            foreach ($unapprovedTags as $tag) {
                $entityManager->persist(new NodeGroupTag($group, $tag, $editor));
            }
            foreach ($approvedTags as $tag) {
                $groupTag = new NodeGroupTag($group, $tag, $editor);
                $groupTag->setApproved(true);
                $entityManager->persist($groupTag);
            }

            return $group;
        };

        $groupWithPendingTag = $createGroup('Hochallee north part', [$createNode(), $createNode()], [$unapprovedTag, $otherKeyTag]);
        $groupWithoutPendingTag = $createGroup('Approved only part', [$createNode(), $createNode()], [], [$approvedTag]);
        $nextPendingGroup = $createGroup('Hochallee south part', [$createNode(), $createNode()], [$nextUnapprovedTag]);

        // The list orders relations by their latest unapproved tag's
        // updated_at, so the newer tag is listed first.
        $unapprovedTag->setUpdatedAt(new \DateTimeImmutable('-2 hours'));
        $otherKeyTag->setUpdatedAt(new \DateTimeImmutable('-1 hour'));
        $nextUnapprovedTag->setUpdatedAt(new \DateTimeImmutable('-30 minutes'));

        $entityManager->flush();
        $entityManager->clear();

        try {
            // Nodes and areas share the combined pending observations list.
            $client->request('GET', '/admin/pending-observations');

            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('table', $groupWithPendingTag->getName());
            self::assertSelectorTextContains('table', (string) $unapprovedTag);
            self::assertSelectorExists(sprintf('a[href*="/admin/pending-relation-observation/%d/edit"]', $groupWithPendingTag->getId()));
            // Relations without unapproved tags are filtered out.
            $tableText = $client->getCrawler()->filter('table')->text();
            self::assertStringNotContainsString($groupWithoutPendingTag->getName(), $tableText);
            // Relations whose pending tags changed most recently come first.
            self::assertLessThan(
                strpos($tableText, $groupWithPendingTag->getName()),
                strpos($tableText, $nextPendingGroup->getName()),
            );

            // The review view shows the relation's unapproved tags and
            // members, without a relation edit form.
            $client->request('GET', '/admin/pending-relation-observation/'.$groupWithPendingTag->getId().'/edit');

            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('body', 'Unapproved tags');
            self::assertSelectorTextContains('body', $unapprovedTag->getValue());
            self::assertSelectorTextContains('body', $groupWithPendingTag->getName());
            self::assertSelectorExists(sprintf('a[href="/admin/node/%d/edit"]', $groupWithPendingTag->getMembers()->first()->getNode()->getId()));
            // The first key group is expanded by default, later ones are not.
            $groups = $client->getCrawler()->filter('details.unapproved-tag-key-group');
            self::assertSame(2, $groups->count());
            self::assertNotNull($groups->first()->attr('open'));
            self::assertNull($groups->eq(1)->attr('open'));
            // The edit view offers a button to the next pending relation.
            self::assertSelectorTextContains('body', 'Next pending');
            self::assertSelectorExists(sprintf('a[href*="/admin/node-group/%d/next-pending"]', $groupWithPendingTag->getId()));
            // The combined list is reachable from the sidebar.
            self::assertSelectorExists('a[href*="/admin/pending-observations"]');

            // The button opens the edit view of the next relation with
            // unapproved tags, skipping relations without any.
            $client->request('GET', '/admin/node-group/'.$groupWithPendingTag->getId().'/next-pending');
            self::assertResponseRedirects('/admin/pending-relation-observation/'.$nextPendingGroup->getId().'/edit');
        } finally {
            $manager = static::getContainer()->get('doctrine')->getManager();
            $manager->clear();
            $nodeIds = [];
            foreach ([$groupWithPendingTag, $groupWithoutPendingTag, $nextPendingGroup] as $group) {
                $group = $manager->find(NodeGroup::class, $group->getId());
                foreach ($group->getMembers() as $member) {
                    $nodeIds[] = $member->getNode()->getId();
                }
                $manager->remove($group);
            }
            $manager->flush();
            foreach ($nodeIds as $nodeId) {
                $manager->remove($manager->find(Node::class, $nodeId));
            }
            $manager->remove($manager->find(Tag::class, $unapprovedTag->getId()));
            $manager->remove($manager->find(Tag::class, $approvedTag->getId()));
            $manager->remove($manager->find(Tag::class, $otherKeyTag->getId()));
            $manager->remove($manager->find(Tag::class, $nextUnapprovedTag->getId()));
            $manager->flush();
        }
    }
}
