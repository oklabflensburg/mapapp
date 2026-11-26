<?php

namespace App\Tests\Controller;

use App\Entity\Node;
use App\Entity\NodeGroup;
use App\Entity\NodeGroupTag;
use App\Entity\NodeProvider;
use App\Entity\NodeTag;
use App\Entity\NodeType;
use App\Entity\Tag;
use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Approving a tag on a node or relation supersedes unapproved tags with the
 * same key on that same node or relation, and approves the value only
 * there: the same value on other nodes or relations keeps its own pending
 * state.
 */
final class TagApprovalCleanupTest extends WebTestCase
{
    use CreatesUsers;

    /** @var list<int> */
    private array $nodeIds = [];

    /** @var list<int> */
    private array $groupIds = [];

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
        foreach ($this->groupIds as $id) {
            $group = $manager->find(NodeGroup::class, $id);
            if ($group !== null) {
                $manager->remove($group);
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

    /**
     * The tag entity is shared per key/value pair, so the same value on two
     * nodes reuses one tag with two rows.
     */
    private function findOrCreateTag(string $key, string $value): Tag
    {
        $manager = static::getContainer()->get('doctrine')->getManager();
        $tag = $manager->getRepository(Tag::class)->findOneBy(['key' => $key, 'value' => $value]);
        if ($tag === null) {
            $tag = new Tag();
            $tag->setKey($key);
            $tag->setValue($value);
            $manager->persist($tag);
        }

        return $tag;
    }

    private function addTag(Node $node, string $key, string $value, bool $approved): NodeTag
    {
        $manager = static::getContainer()->get('doctrine')->getManager();

        $tag = $this->findOrCreateTag($key, $value);

        $nodeTag = new NodeTag($node, $tag, $node->getCreatedByUser());
        $nodeTag->setApproved($approved);
        // The row is attached to the node's collection directly: the admin
        // request reuses the same managed node, whose in-memory collection
        // would otherwise not know about the new row.
        $node->getNodeTags()->add($nodeTag);
        $manager->persist($nodeTag);
        $manager->flush();
        $this->tagIds[] = $tag->getId();

        return $nodeTag;
    }

    private function createRelationTag(NodeGroup $group, string $key, string $value, bool $approved, User $user): NodeGroupTag
    {
        $manager = static::getContainer()->get('doctrine')->getManager();

        $tag = $this->findOrCreateTag($key, $value);

        $groupTag = new NodeGroupTag($group, $tag, $user);
        $groupTag->setApproved($approved);
        // Attached directly for the same reason as in addTag().
        $group->getGroupTags()->add($groupTag);
        $manager->persist($groupTag);
        $manager->flush();
        $this->tagIds[] = $tag->getId();

        return $groupTag;
    }

    /**
     * Approves the node tag through the review form on the node edit page,
     * as an admin would (see admin/_unapproved_tags.html.twig).
     */
    private function approveNodeTag(KernelBrowser $client, NodeTag $nodeTag): void
    {
        $crawler = $client->request('GET', '/admin/node/'.$nodeTag->getNode()->getId().'/edit');
        self::assertResponseIsSuccessful();

        $form = $crawler
            ->filter(sprintf('form[action="/admin/node-tag/%d/approve"]', $nodeTag->getId()))
            ->form();
        $client->submit($form);
        self::assertResponseRedirects();
    }

    /**
     * Approves the relation tag through the review form on the relation
     * review page, explicitly targeting the approve button.
     */
    private function approveGroupTag(KernelBrowser $client, NodeGroupTag $groupTag): void
    {
        $crawler = $client->request('GET', '/admin/pending-relation-observation/'.$groupTag->getGroup()->getId().'/edit');
        self::assertResponseIsSuccessful();

        $form = $crawler
            ->filter(sprintf('form[action="/admin/node-group-tag/%d/approve"] button[name$="[approve]"]', $groupTag->getId()))
            ->form();
        $client->submit($form);
        self::assertResponseRedirects();
    }

    /**
     * @return list<string> Tag key=value pairs added to the relation.
     */
    private function groupTagSummaries(NodeGroup $group): array
    {
        $summaries = [];
        foreach ($group->getGroupTags() as $groupTag) {
            $summaries[] = $groupTag->getTag()->getKey().'='.$groupTag->getTag()->getValue();
        }
        sort($summaries);

        return $summaries;
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

    public function testApprovingTagOnNodeSupersedesCompetingValuesOnlyThere(): void
    {
        $identity = bin2hex(random_bytes(8));
        $client = static::createClient();

        $node = $this->createNode('tag-approval-'.$identity);
        $french = $this->addTag($node, 'cuisine', 'french-'.$identity, false);
        $this->addTag($node, 'cuisine', 'italian-'.$identity, false);
        $access = $this->addTag($node, 'access', 'yes-'.$identity, false);
        // The same value on another node stays untouched by the approval.
        $otherNode = $this->createNode('tag-approval-other-'.$identity);
        $frenchElsewhere = $this->addTag($otherNode, 'cuisine', 'french-'.$identity, false);

        $client->loginUser($this->createUser('admin-tag-approval@example.test', ['admin']), 'admin');
        $this->approveNodeTag($client, $french);

        $manager = static::getContainer()->get('doctrine')->getManager();
        $manager->clear();

        // The competing unapproved cuisine value is superseded; other keys stay.
        self::assertSame(['access=yes-'.$identity, 'cuisine=french-'.$identity], $this->nodeTagSummaries($manager->find(Node::class, $node->getId())));
        // The approved row and the untouched key keep their approval state.
        $approvedFrench = $manager->find(NodeTag::class, $french->getId());
        self::assertTrue($approvedFrench->isApproved());
        self::assertFalse($manager->find(NodeTag::class, $access->getId())->isApproved());
        // The same value on the other node is still pending.
        $elsewhere = $manager->find(NodeTag::class, $frenchElsewhere->getId());
        self::assertFalse($elsewhere->isApproved());
        self::assertSame(['cuisine=french-'.$identity], $this->nodeTagSummaries($manager->find(Node::class, $otherNode->getId())));
    }

    public function testApprovingTagOnRelationSupersedesOnlyTheSameRange(): void
    {
        $identity = bin2hex(random_bytes(8));
        $client = static::createClient();
        $manager = static::getContainer()->get('doctrine')->getManager();
        $editor = $this->createUser('tag-approval-relation-editor@example.test', ['editor']);
        $otherEditor = $this->createUser('tag-approval-relation-other@example.test', ['editor']);

        // Each observation is one relation (specs/map-editing/observation-
        // relations/spec.md): two pending surface observations on the same
        // range (prepared as two editors' rows here), plus same-key
        // observations with other ranges that must stay pending.
        $rangeNodes = [$this->createNode('tag-approval-range-a-'.$identity), $this->createNode('tag-approval-range-b-'.$identity)];
        $otherRangeNodes = [$this->createNode('tag-approval-other-a-'.$identity), $this->createNode('tag-approval-other-b-'.$identity)];

        $group = new NodeGroup();
        $group->setCreatedByUser($editor);
        $manager->persist($group);
        foreach ($rangeNodes as $position => $node) {
            $member = new \App\Entity\NodeGroupMember($group, $node, $position);
            $manager->persist($member);
            $group->getMembers()->add($member);
        }

        $sameRangeGroup = new NodeGroup();
        $sameRangeGroup->setCreatedByUser($editor);
        $manager->persist($sameRangeGroup);
        foreach ($rangeNodes as $position => $node) {
            $member = new \App\Entity\NodeGroupMember($sameRangeGroup, $node, $position);
            $manager->persist($member);
            $sameRangeGroup->getMembers()->add($member);
        }

        $otherRangeGroup = new NodeGroup();
        $otherRangeGroup->setCreatedByUser($editor);
        $manager->persist($otherRangeGroup);
        foreach ($otherRangeNodes as $position => $node) {
            $member = new \App\Entity\NodeGroupMember($otherRangeGroup, $node, $position);
            $manager->persist($member);
            $otherRangeGroup->getMembers()->add($member);
        }
        $manager->flush();
        foreach ([$group, $sameRangeGroup, $otherRangeGroup] as $created) {
            $this->groupIds[] = $created->getId();
        }

        $cobblestone = $this->createRelationTag($group, 'surface', 'cobblestone-'.$identity, false, $editor);
        $asphaltElsewhereSameRange = $this->createRelationTag($sameRangeGroup, 'surface', 'asphalt-'.$identity, false, $otherEditor);
        $gravelOtherRange = $this->createRelationTag($otherRangeGroup, 'surface', 'gravel-'.$identity, false, $otherEditor);
        // A different key on the same range stays.
        $this->createRelationTag($sameRangeGroup, 'lit', 'yes-'.$identity, false, $otherEditor);
        $manager->flush();

        $client->loginUser($this->createUser('admin-tag-approval-relation@example.test', ['admin']), 'admin');
        $this->approveGroupTag($client, $cobblestone);

        $manager->clear();

        // The approved value publishes; a competing pending surface on the
        // same range is superseded (even across relations), other keys stay,
        // and a same-key observation on a different range is independent.
        self::assertSame(['surface=cobblestone-'.$identity], $this->groupTagSummaries($manager->find(NodeGroup::class, $group->getId())));
        $sameRange = $manager->find(NodeGroup::class, $sameRangeGroup->getId());
        self::assertSame(['lit=yes-'.$identity], $this->groupTagSummaries($sameRange));
        self::assertTrue($manager->find(NodeGroupTag::class, $cobblestone->getId())->isApproved());
        $removed = $manager->find(NodeGroupTag::class, $asphaltElsewhereSameRange->getId());
        self::assertNull($removed);
        $keptRange = $manager->find(NodeGroup::class, $otherRangeGroup->getId());
        self::assertSame(['surface=gravel-'.$identity], $this->groupTagSummaries($keptRange));
        self::assertFalse($manager->find(NodeGroupTag::class, $gravelOtherRange->getId())->isApproved());
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
            'tags' => [['key' => 'cuisine', 'value' => 'french-'.$identity], ['key' => 'access', 'value' => 'yes-'.$identity]],
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
            'tags' => [['key' => 'cuisine', 'value' => 'italian-'.$identity]],
        ]);
        self::assertResponseStatusCodeSame(200);

        $manager = static::getContainer()->get('doctrine')->getManager();
        $manager->clear();
        $node = $manager->find(Node::class, $nodeId);

        // The admin's approved cuisine value supersedes the editor's.
        self::assertSame(['access=yes-'.$identity, 'cuisine=italian-'.$identity], $this->nodeTagSummaries($node));
        foreach ($node->getNodeTags() as $nodeTag) {
            if ($nodeTag->getTag()->getKey() === 'cuisine') {
                self::assertTrue($nodeTag->isApproved());
            }
        }
    }
}
