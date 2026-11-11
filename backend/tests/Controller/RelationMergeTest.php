<?php

namespace App\Tests\Controller;

use App\Entity\Node;
use App\Entity\NodeGroup;
use App\Entity\NodeGroupTag;
use App\Entity\Tag;
use App\Entity\User;
use App\Repository\NodeProviderRepository;
use App\Repository\NodeTypeRepository;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The admin merge decision for overlapping relations: the review screen
 * offers relations that share a parent way node and whose corridor areas
 * intersect, with a target choice (which relation survives) and a per-row
 * resolution of competing tag keys. See specs/map-editing/relation-merge/
 * spec.md.
 */
final class RelationMergeTest extends WebTestCase
{
    use CreatesUsers;

    /** The shared way runs eastward along this latitude. */
    private const WAY_LATITUDE = 54.791;

    /** The longitudes of the fixtures, one member per 0.0001 (about 6 m). */
    private const WAY_LONGITUDE = 9.4370;

    /** @var list<int> */
    private array $groupIds = [];

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
        // Groups cascade their members and tag rows; nodes and shared tags
        // let go of them first.
        foreach ($this->groupIds as $id) {
            $group = $manager->find(NodeGroup::class, $id);
            if ($group !== null) {
                foreach ($group->getGroupTags() as $entry) {
                    $this->tagIds[] = $entry->getTag()->getId();
                }
                $manager->remove($group);
            }
        }
        $manager->flush();
        foreach ($this->nodeIds as $id) {
            $node = $manager->find(Node::class, $id);
            if ($node !== null) {
                $manager->remove($node);
            }
        }
        foreach (array_unique($this->tagIds) as $id) {
        $tag = $manager->find(Tag::class, $id);
            if ($tag !== null) {
                $manager->remove($tag);
            }
        }
        $manager->flush();
        parent::tearDown();
    }

    /**
     * The OSM way node the members' parent relation points at: the shared
     * way identity every overlap requires.
     */
    private function createWayNode(string $identity, float $latitude, float $longitude): Node
    {
        return $this->createNode('w-'.$identity, $latitude, $longitude, null, 'OSM', 'osm');
    }

    /**
     * Relation members are the editor's local nodes marking positions
     * along the way; their parent is the OSM way node.
     */
    private function createMemberNode(string $identity, int $sequence, Node $way, float $latitude, float $longitude): Node
    {
        return $this->createNode('n-'.$identity.'-'.$sequence, $latitude, $longitude, $way, 'local', 'user');
    }

    private function createNode(string $providerNodeId, float $latitude, float $longitude, ?Node $parent, string $provider, string $type): Node
    {
        $manager = static::getContainer()->get('doctrine')->getManager();
        $node = new Node();
        $node->setNodeType(static::getContainer()->get(NodeTypeRepository::class)->findOrCreate($type));
        $node->setNodeProvider(static::getContainer()->get(NodeProviderRepository::class)->findOrCreate($provider));
        $node->setProviderNodeId($providerNodeId);
        $node->setLatitude($latitude);
        $node->setLongitude($longitude);
        $node->setCreatedByUser($this->anonymousUser());
        if ($parent !== null) {
            $node->setParent($parent);
        }
        $manager->persist($node);
        $manager->flush();
        $this->nodeIds[] = $node->getId();

        return $node;
    }

    /**
     * @param list<Node> $nodes ordered along the way, at least two
     */
    private function createRelation(string $name, array $nodes): NodeGroup
    {
        $manager = static::getContainer()->get('doctrine')->getManager();
        $group = new NodeGroup();
        $group->setName($name);
        $group->setCreatedByUser($this->anonymousUser());
        $manager->persist($group);

        $first = $group->addMember($nodes[0]);
        if ($first === null) {
            self::fail('The first member must attach.');
        }
        $manager->persist($first);
        foreach (\array_slice($nodes, 1) as $node) {
            $member = $group->addMember($node);
            if ($member !== null) {
                $manager->persist($member);
            }
        }
        $manager->flush();
        $this->groupIds[] = $group->getId();

        return $group;
    }

    private function addRelationTag(NodeGroup $group, User $user, string $key, string $value, bool $approved): NodeGroupTag
    {
        $manager = static::getContainer()->get('doctrine')->getManager();
        $tag = $manager->getRepository(Tag::class)->findOneBy(['key' => $key, 'value' => $value]);
        if ($tag === null) {
            $tag = new Tag();
            $tag->setKey($key);
            $tag->setValue($value);
            $manager->persist($tag);
        }

        $groupTag = new NodeGroupTag($group, $tag, $user);
        $groupTag->setApproved($approved);
        // Attached directly: an admin request reuses the same managed
        // group, whose in-memory collection must know about the new row.
        $group->getGroupTags()->add($groupTag);
        $manager->persist($groupTag);
        $manager->flush();
        $this->tagIds[] = $tag->getId();

        return $groupTag;
    }

    /**
     * User 1's approved relation on a way and user 2's pending relation
     * overlapping it: they share the way node as the members' parent, and
     * the pending relation shares two member nodes while reaching one node
     * beyond either end.
     *
     * @return array{0: NodeGroup, 1: NodeGroup, 2: User, 3: User}
     */
    private function overlappingPair(string $identity): array
    {
        $user1 = $this->createUser('relation-merge-user1-'.$identity.'@example.test', ['editor']);
        $user2 = $this->createUser('relation-merge-user2-'.$identity.'@example.test', ['editor']);

        $longitude = self::WAY_LONGITUDE;
        $way = $this->createWayNode($identity, self::WAY_LATITUDE, $longitude);

        $a0 = $this->createMemberNode($identity, 0, $way, self::WAY_LATITUDE, $longitude);
        $a1 = $this->createMemberNode($identity, 1, $way, self::WAY_LATITUDE, $longitude + 0.0001);
        $a2 = $this->createMemberNode($identity, 2, $way, self::WAY_LATITUDE, $longitude + 0.0002);
        $a3 = $this->createMemberNode($identity, 3, $way, self::WAY_LATITUDE, $longitude + 0.0003);
        $before = $this->createMemberNode($identity, 4, $way, self::WAY_LATITUDE, $longitude - 0.0001);
        $after = $this->createMemberNode($identity, 5, $way, self::WAY_LATITUDE, $longitude + 0.0004);

        $approved = $this->createRelation('approved-part-'.$identity, [$a0, $a1, $a2, $a3]);
        $approvedRow = $this->addRelationTag($approved, $user1, 'surface', 'asphalt', true);
        self::assertTrue($approvedRow->isApproved());

        $pending = $this->createRelation('pending-part-'.$identity, [$before, $a1, $a2, $after]);
        $this->addRelationTag($pending, $user2, 'surface', 'paved', false);
        $this->addRelationTag($pending, $user2, 'lit', 'yes', false);

        return [$approved, $pending, $user1, $user2];
    }

    private function anonymousUser(): User
    {
        return $this->createUser('relation-merge-anonymous@example.test', ['editor']);
    }

    private function loginAdmin(KernelBrowser $client): User
    {
        $admin = $this->createUser('relation-merge-admin-'.bin2hex(random_bytes(3)).'@example.test', ['admin']);
        $client->loginUser($admin, 'admin');

        return $admin;
    }

    private function reviewPage(KernelBrowser $client, NodeGroup $group): Crawler
    {
        return $client->request('GET', '/admin/pending-relation-observation/'.$group->getId().'/edit');
    }

    /**
     * The rendered merge form as plain POST values: CSRF token, the
     * render-time snapshot and the competing rows checked by default.
     * Repeated checkbox fields cannot be addressed through the DomCrawler
     * form object, which only ever sees the last input of a name.
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function mergeRequest(KernelBrowser $client, NodeGroup $reviewed, NodeGroup $candidate): array
    {
        $crawler = $this->reviewPage($client, $reviewed);
        self::assertResponseIsSuccessful();
        $form = $crawler
            ->filter(sprintf('form[action="/admin/node-group/%d/merge/%d"]', $reviewed->getId(), $candidate->getId()));
        $keep = [];
        foreach ($form->filterXPath('//input[@name="merge_keep[]" and @checked]') as $checkbox) {
            $keep[] = $checkbox->getAttribute('value');
        }

        return [$form->attr('action'), [
            '_token' => $form->filter('input[name="_token"]')->attr('value'),
            'merge_snapshot' => $form->filter('input[name="merge_snapshot"]')->attr('value'),
            'merge_keep' => $keep,
        ]];
    }

    /**
     * Merges the pending relation into the candidate (or vice versa) and
     * asserts the redirect of a successful merge. The kept rows replace
     * the rendered checkbox defaults.
     *
     * @param list<int> $keep
     */
    private function merge(KernelBrowser $client, NodeGroup $reviewed, NodeGroup $candidate, string $target, array $keep = []): void
    {
        [$uri, $values] = $this->mergeRequest($client, $reviewed, $candidate);
        $values['merge_target'] = $target;
        $values['merge_keep'] = array_map('strval', $keep);
        $client->request('POST', $uri, $values);
        self::assertResponseRedirects();
    }

    /**
     * Fresh managed groups after a merge, all from the same clear epoch so
     * lazy collections stay usable.
     *
     * @return list<NodeGroup|null>
     */
    private function fetchGroups(NodeGroup ...$groups): array
    {
        $manager = static::getContainer()->get('doctrine')->getManager();
        if (!$manager->isOpen()) {
            $manager = static::getContainer()->get('doctrine')->resetManager();
        }
        $manager->clear();

        $fetched = [];
        foreach ($groups as $group) {
            $fetched[] = $manager->find(NodeGroup::class, $group->getId());
        }

        return $fetched;
    }

    /**
     * @return list<string> "user:key=value [state]" per tag row
     */
    private function tagRowSummaries(NodeGroup $group): array
    {
        $summaries = [];
        foreach ($group->getGroupTags() as $entry) {
            $summaries[] = $entry->getCreatedByUser()->getUserIdentifier().':'
                .$entry->getTag()->getKey().'='.$entry->getTag()->getValue()
                .($entry->isApproved() ? ' [approved]' : ' [pending]');
        }
        sort($summaries);

        return $summaries;
    }

    /**
     * @return list<array{id: int, key: string, value: string, approved: bool, user: string}>
     */
    private function tagRows(NodeGroup $group): array
    {
        $rows = [];
        foreach ($group->getGroupTags() as $entry) {
            $rows[] = [
                'id' => $entry->getId() ?? 0,
                'key' => $entry->getTag()->getKey(),
                'value' => $entry->getTag()->getValue(),
                'approved' => $entry->isApproved(),
                'user' => $entry->getCreatedByUser()->getUserIdentifier(),
            ];
        }

        return $rows;
    }

    /**
     * @return list<float> member longitudes, rounded
     */
    private function memberLongitudes(NodeGroup $group): array
    {
        $longitudes = [];
        foreach ($group->getMembers() as $member) {
            $longitudes[] = round($member->getNode()->getLongitude(), 4);
        }

        return $longitudes;
    }

    /**
     * AC1/R1/R2/R3: only relations sharing a parent way node, with an
     * approved tag and an intersecting corridor, surface as merge
     * candidates; the form offers both targets and lists competing keys.
     */
    public function testReviewListsOnlyOverlappingApprovedRelations(): void
    {
        $identity = bin2hex(random_bytes(3));
        $client = static::createClient();
        $this->loginAdmin($client);

        [$approved, $pending] = $this->overlappingPair($identity);
        $way = $pending->getMembers()->first()->getNode()->getParent();

        // Approved relation at the same way, 30 m away: disjoint corridors.
        $awayA = $this->createMemberNode($identity.'a', 0, $way, self::WAY_LATITUDE, 9.4400);
        $awayB = $this->createMemberNode($identity.'a', 1, $way, self::WAY_LATITUDE, 9.4401);
        $away = $this->createRelation('away-part-'.$identity, [$awayA, $awayB]);
        $this->addRelationTag($away, $this->anonymousUser(), 'incline', 'up', true);

        // Pending relation overlapping the reviewed one: no approved tag.
        $nearA = $this->createMemberNode($identity.'q', 0, $way, self::WAY_LATITUDE, 9.4370 + 0.0001);
        $nearB = $this->createMemberNode($identity.'q', 1, $way, self::WAY_LATITUDE, 9.4370 + 0.0002);
        $alsoPending = $this->createRelation('other-pending-'.$identity, [$nearA, $nearB]);
        $this->addRelationTag($alsoPending, $this->anonymousUser(), 'surface', 'concrete', false);

        // Approved relation crossing the corridor on another way: the
        // corridors intersect but no parent way node is shared.
        $crossingWay = $this->createWayNode($identity.'x', self::WAY_LATITUDE, 9.43715);
        $crossingA = $this->createMemberNode($identity.'x', 0, $crossingWay, self::WAY_LATITUDE - 0.0002, 9.43715);
        $crossingB = $this->createMemberNode($identity.'x', 1, $crossingWay, self::WAY_LATITUDE + 0.0002, 9.43715);
        $crossing = $this->createRelation('crossing-part-'.$identity, [$crossingA, $crossingB]);
        $this->addRelationTag($crossing, $this->anonymousUser(), 'incline', 'down', true);

        $crawler = $this->reviewPage($client, $pending);
        self::assertResponseIsSuccessful();

        $mergeSection = $crawler->filter('.relation-merge');
        self::assertSame(1, $mergeSection->count(), 'the merge section renders');
        self::assertSame(1, $mergeSection->filter('form')->count(), 'exactly one candidate is offered');
        self::assertStringContainsString('approved-part-'.$identity, $mergeSection->text());
        self::assertStringNotContainsString('away-part-'.$identity, $mergeSection->text());
        self::assertStringNotContainsString('other-pending-'.$identity, $mergeSection->text());
        self::assertStringNotContainsString('crossing-part-'.$identity, $mergeSection->text());

        // Both merge directions; snapshot and CSRF inputs travel along.
        $options = $mergeSection->filter('select[name="merge_target"] option');
        self::assertSame(2, $options->count());
        self::assertSame('candidate', $options->eq(0)->attr('value'));
        self::assertSame('reviewed', $options->eq(1)->attr('value'));
        self::assertSame(1, $mergeSection->filter('input[name="merge_snapshot"]')->count());
        self::assertSame(1, $mergeSection->filter('input[name="_token"]')->count());

        // Competing keys list both surface rows; `lit` lives on one side
        // only, so it never appears, and approved rows default to checked.
        $conflict = $mergeSection->filter('.relation-merge-conflict');
        self::assertSame(1, $conflict->count());
        self::assertStringContainsString('asphalt', $conflict->text());
        self::assertStringContainsString('paved', $conflict->text());
        self::assertStringNotContainsString('lit', $conflict->text());
        $checkboxes = $conflict->filter('input[name="merge_keep[]"]');
        self::assertSame(2, $checkboxes->count());
        // Reviewed rows come first (the pending proposal), candidate rows
        // second (the approved value): only approved ones default to checked
        // (a boolean HTML attribute carries an empty value).
        self::assertNull($checkboxes->eq(0)->attr('checked'));
        self::assertSame('', $checkboxes->eq(1)->attr('checked'));
    }

    /**
     * AC2/AC5/AC8: merging into the approved candidate moves the union of
     * members along the way, moves the kept pending rows with attribution
     * (still pending), and deletes the absorbed relation.
     */
    public function testMergeIntoCandidateMovesPendingTagsAndMembers(): void
    {
        $identity = bin2hex(random_bytes(3));
        $client = static::createClient();
        $this->loginAdmin($client);

        [$approved, $pending, $user1, $user2] = $this->overlappingPair($identity);
        $base = self::WAY_LONGITUDE;

        $this->merge($client, $pending, $approved, 'candidate', [
            $this->rowId($approved, 'surface', true),
            $this->rowId($pending, 'surface', false),
        ]);

        [$merged, $absorbed] = $this->fetchGroups($approved, $pending);
        self::assertNotNull($merged);
        self::assertNull($absorbed, 'the absorbed relation is gone');

        // The union of both member sets, each node once, in along-way
        // order; the `before` node landed in front of the first approved
        // member and `after` behind the last.
        self::assertSame(
            array_map(static fn (float $longitude): float => round($longitude, 4), [
                $base - 0.0001, $base, $base + 0.0001, $base + 0.0002, $base + 0.0003, $base + 0.0004,
            ]),
            $this->memberLongitudes($merged)
        );

        $summaries = $this->tagRowSummaries($merged);
        self::assertContains($user1->getUserIdentifier().':surface=asphalt [approved]', $summaries);
        self::assertContains($user2->getUserIdentifier().':surface=paved [pending]', $summaries);
        self::assertContains($user2->getUserIdentifier().':lit=yes [pending]', $summaries);

        // Updating the absorbed id fails, as it does for any removed relation.
        $client->jsonRequest('POST', '/api/login', [
            'email' => $user1->getEmail(),
            'password' => 'correct horse battery staple',
        ]);
        self::assertResponseIsSuccessful();
        $client->jsonRequest('POST', '/api/node-groups', [
            'id' => $pending->getId(),
            'members' => [],
            'tags' => [],
        ]);
        self::assertResponseStatusCodeSame(422);
    }

    /**
     * AC9/R6: merging into the reviewed pending relation keeps its queue
     * entry; the candidate's approved rows become public with their
     * original attribution, and pending rows stay private.
     */
    public function testMergeIntoReviewedAbsorbsApprovedCandidate(): void
    {
        $identity = bin2hex(random_bytes(3));
        $client = static::createClient();
        $this->loginAdmin($client);

        [$approved, $pending, $user1, $user2] = $this->overlappingPair($identity);

        $this->merge($client, $pending, $approved, 'reviewed', [
            $this->rowId($approved, 'surface', true),
            $this->rowId($pending, 'surface', false),
        ]);

        [$survivor, $absorbed] = $this->fetchGroups($pending, $approved);
        self::assertNotNull($survivor);
        self::assertNull($absorbed, 'the approved candidate is absorbed');

        // The candidate's approved row survives with its attribution,
        // public through the groups list — while user 2's still-pending
        // rows stay invisible to other visitors (admin here).
        $summaries = $this->tagRowSummaries($survivor);
        self::assertContains($user1->getUserIdentifier().':surface=asphalt [approved]', $summaries);
        self::assertContains($user2->getUserIdentifier().':lit=yes [pending]', $summaries);

        // The follow-up review continues on the surviving relation with
        // the still-pending merged rows.
        $crawler = $this->reviewPage($client, $survivor);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('pending-part-'.$identity, $crawler->filter('.relation-review-summary h1')->text());

        $client->jsonRequest('GET', '/api/node-groups');
        self::assertResponseIsSuccessful();
        $groups = json_decode((string) $client->getResponse()->getContent(), true);
        $ids = array_column($groups, 'id');
        self::assertNotContains($approved->getId(), $ids);
        foreach ($groups as $group) {
            if ($group['id'] !== $pending->getId()) {
                continue;
            }
            $keys = array_column($group['tags'], 'key');
            self::assertContains('surface', $keys);
            self::assertNotContains('lit', $keys, 'pending rows stay private to their contributor');
        }
    }

    /**
     * AC3: multiple approved values of a competing key resolve to the
     * selected row only; the others leave no trace.
     */
    public function testCompetingApprovedValuesResolveToTheSelectedRow(): void
    {
        $identity = bin2hex(random_bytes(3));
        $client = static::createClient();
        $this->loginAdmin($client);

        [$approved, $pending, $user1] = $this->overlappingPair($identity);

        // Both relations carry an approved surface with different values;
        // the approval listener superseded user 2's pending proposal on
        // the pending relation, as any approval does.
        $this->addRelationTag($pending, $user1, 'surface', 'paved', true);

        // The admin keeps the approved relation's asphalt row only.
        $this->merge($client, $pending, $approved, 'candidate', [
            $this->rowId($approved, 'surface', true),
        ]);

        [$merged, $absorbed] = $this->fetchGroups($approved, $pending);
        self::assertNotNull($merged);
        self::assertNull($absorbed);

        $surfaces = [];
        foreach ($this->tagRows($merged) as $row) {
            if ($row['key'] === 'surface') {
                $surfaces[] = $row;
            }
        }
        self::assertCount(1, $surfaces);
        self::assertSame('asphalt', $surfaces[0]['value']);
        self::assertTrue($surfaces[0]['approved']);
    }

    /**
     * AC4: distinct aspects of the same structured element merge without
     * a decision; only equal keys compete.
     */
    public function testDifferentAspectsOfTheSameElementMergeWithoutDecision(): void
    {
        $identity = bin2hex(random_bytes(3));
        $client = static::createClient();
        $this->loginAdmin($client);

        [$approved, $pending, $user1, $user2] = $this->overlappingPair($identity);
        $this->addRelationTag($approved, $user1, 'way.footwalk.1.width', '<1', true);
        $this->addRelationTag($pending, $user2, 'way.footwalk.1.surface', 'asphalt', true);

        $crawler = $this->reviewPage($client, $pending);
        self::assertResponseIsSuccessful();
        $form = $crawler
            ->filter(sprintf('form[action="/admin/node-group/%d/merge/%d"]', $pending->getId(), $approved->getId()));

        $conflict = $crawler->filter('.relation-merge-conflict')->text();
        self::assertStringNotContainsString('way.footwalk.1.width', $conflict);
        self::assertStringNotContainsString('way.footwalk.1.surface', $conflict);
        self::assertStringContainsString('surface', $conflict, 'the plain surface key alone competes');

        // Let the rendered defaults apply: only approved rows are checked.
        [$uri, $values] = $this->mergeRequest($client, $pending, $approved);
        $values['merge_target'] = 'candidate';
        $client->request('POST', $uri, $values);
        self::assertResponseRedirects();

        [$merged, $absorbed] = $this->fetchGroups($approved, $pending);
        self::assertNotNull($merged);
        self::assertNull($absorbed);

        $summaries = $this->tagRowSummaries($merged);
        self::assertContains($user1->getUserIdentifier().':way.footwalk.1.width=<1 [approved]', $summaries);
        self::assertContains($user2->getUserIdentifier().':way.footwalk.1.surface=asphalt [approved]', $summaries);
        self::assertContains($user1->getUserIdentifier().':surface=asphalt [approved]', $summaries);

        foreach ($this->tagRows($merged) as $row) {
            if ($row['key'] === 'surface') {
                self::assertSame('asphalt', $row['value'], 'the unchecked plain proposal is discarded');
            }
        }
    }

    /**
     * AC6: identical (tag, contributor) rows never duplicate; the
     * survivor's row wins.
     */
    public function testIdenticalContributorRowsNeverDuplicate(): void
    {
        $identity = bin2hex(random_bytes(3));
        $client = static::createClient();
        $this->loginAdmin($client);

        [$approved, $pending, $user1] = $this->overlappingPair($identity);
        $this->addRelationTag($pending, $user1, 'surface', 'asphalt', false);

        // Both rows of the identical (tag, contributor) pair stay hidden
        // from the conflict list — the survivor's row wins automatically —
        // and the drawn-out competing proposal is unchecked by default.
        $this->merge($client, $pending, $approved, 'candidate', []);

        [$merged, $absorbed] = $this->fetchGroups($approved, $pending);
        self::assertNotNull($merged);
        self::assertNull($absorbed);

        $matches = 0;
        foreach ($this->tagRows($merged) as $row) {
            if ($row['key'] === 'surface' && $row['value'] === 'asphalt' && $row['user'] === $user1->getUserIdentifier()) {
                $matches++;
                self::assertTrue($row['approved']);
            }
        }
        self::assertSame(1, $matches, 'the survivor row wins without a unique-constraint failure');
    }

    /**
     * AC7: a candidate that changed since the merge screen rendered makes
     * the merge stale; nothing changes in either relation.
     */
    public function testStaleSnapshotIsRejectedWithoutChanges(): void
    {
        $identity = bin2hex(random_bytes(3));
        $client = static::createClient();
        $this->loginAdmin($client);

        [$approved, $pending, $user1] = $this->overlappingPair($identity);

        [$uri, $values] = $this->mergeRequest($client, $pending, $approved);
        $values['merge_target'] = 'candidate';

        // The candidate gains a tag after the screen rendered.
        $this->addRelationTag($approved, $user1, 'incline', '2%', true);

        $client->request('POST', $uri, $values);
        self::assertResponseStatusCodeSame(409);

        [$reloadedApproved, $reloadedPending] = $this->fetchGroups($approved, $pending);
        self::assertNotNull($reloadedApproved);
        self::assertNotNull($reloadedPending);
        self::assertCount(4, $reloadedApproved->getMembers());
        self::assertCount(4, $reloadedPending->getMembers());
        $keys = array_column($this->tagRows($reloadedApproved), 'key');
        self::assertContains('incline', $keys, 'the late tag is untouched');
    }

    /**
     * AC10: the merge is admin-only and CSRF-protected.
     */
    public function testMergeRequiresAdminRoleAndCsrfToken(): void
    {
        $identity = bin2hex(random_bytes(3));
        $client = static::createClient();

        [$approved, $pending] = $this->overlappingPair($identity);

        // Anonymous users are sent to the login wall.
        $client->request('POST', '/admin/node-group/'.$pending->getId().'/merge/'.$approved->getId());
        self::assertResponseRedirects();

        // Editors get 403 before anything else changes.
        $client->loginUser($this->createUser('relation-merge-editor-'.$identity.'@example.test', ['editor']), 'admin');
        $client->request('POST', '/admin/node-group/'.$pending->getId().'/merge/'.$approved->getId());
        self::assertResponseStatusCodeSame(403);

        // An invalid CSRF token changes nothing for an admin.
        $this->loginAdmin($client);
        $client->request('POST', '/admin/node-group/'.$pending->getId().'/merge/'.$approved->getId(), [
            '_token' => 'invalid',
            'merge_target' => 'candidate',
        ]);
        self::assertResponseStatusCodeSame(403);

        // Nothing was merged by any of the rejected attempts.
        self::assertNotNull($this->fetchGroups($pending, $approved)[0]);
        self::assertNotNull($this->fetchGroups($pending, $approved)[1]);
    }

    /**
     * R5: invalid payloads are rejected without changes.
     */
    public function testInvalidMergePayloadsAreRejected(): void
    {
        $identity = bin2hex(random_bytes(3));
        $client = static::createClient();
        $this->loginAdmin($client);

        [$approved, $pending] = $this->overlappingPair($identity);

        // An unknown target.
        [$uri, $values] = $this->mergeRequest($client, $pending, $approved);
        $values['merge_target'] = 'elsewhere';
        $client->request('POST', $uri, $values);
        self::assertResponseStatusCodeSame(400);

        // An unknown competing-row id.
        [$uri, $values] = $this->mergeRequest($client, $pending, $approved);
        $values['merge_target'] = 'candidate';
        $values['merge_keep'] = ['999999'];
        $client->request('POST', $uri, $values);
        self::assertResponseStatusCodeSame(400);

        // Nothing was merged either way.
        [$reloadedApproved, $reloadedPending] = $this->fetchGroups($approved, $pending);
        self::assertNotNull($reloadedApproved);
        self::assertNotNull($reloadedPending);
        self::assertCount(4, $reloadedApproved->getMembers());
        self::assertCount(4, $reloadedPending->getMembers());
    }

    /**
     * AC11/R1: saving an overlapping relation behaves exactly as today;
     * the overlap affects only the admin review screen.
     */
    public function testSavingOverlappingRelationIsUnaffected(): void
    {
        $identity = bin2hex(random_bytes(3));
        $client = static::createClient();
        $this->loginAdmin($client);

        [$approved, $pending] = $this->overlappingPair($identity);

        // A third editor saves another relation on the same way through the
        // regular API: no warning, no error, today's semantics (see
        // NodeGroupControllerTest).
        $way = $pending->getMembers()->first()->getNode()->getParent();
        $m0 = $this->createMemberNode($identity.'z', 0, $way, self::WAY_LATITUDE, self::WAY_LONGITUDE + 0.0001);
        $m1 = $this->createMemberNode($identity.'z', 1, $way, self::WAY_LATITUDE, self::WAY_LONGITUDE + 0.0002);
        $editorEmail = 'relation-merge-saver-'.$identity.'@example.test';
        $this->createUser($editorEmail, ['editor'], 'correct horse battery staple');
        $client->jsonRequest('POST', '/api/login', ['email' => $editorEmail, 'password' => 'correct horse battery staple']);
        self::assertResponseIsSuccessful();
        $client->jsonRequest('POST', '/api/node-groups', [
            'name' => 'third-part-'.$identity,
            'members' => [
                ['id' => $m0->getId()],
                ['id' => $m1->getId()],
            ],
            'tags' => [['key' => 'surface', 'value' => 'gravel']],
        ]);
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertArrayHasKey('id', $data);
        $this->groupIds[] = $data['id'];

        // The untouched pair still renders its merge decision.
        $crawler = $this->reviewPage($client, $pending);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('approved-part-'.$identity, $crawler->filter('.relation-merge')->text());
    }

    /**
     * The id of a relation's approved or pending row for a key.
     */
    private function rowId(NodeGroup $group, string $key, bool $approved): int
    {
        foreach ($group->getGroupTags() as $entry) {
            if ($entry->getTag()->getKey() === $key && $entry->isApproved() === $approved) {
                return $entry->getId() ?? 0;
            }
        }

        self::fail(sprintf('No %s row for key "%s" on relation #%d.', $approved ? 'approved' : 'pending', $key, $group->getId() ?? 0));
    }
}
