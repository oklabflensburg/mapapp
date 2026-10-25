<?php

namespace App\Tests\Controller;

use App\Entity\Node;
use App\Entity\NodeGroup;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class NodeGroupControllerTest extends WebTestCase
{
    use CreatesUsers;

    private const EDITOR_EMAIL = 'editor@example.test';

    /** @var list<string> */
    private array $memberProviderNodeIds = [];

    /** @var list<int> */
    private array $groupIds = [];

    protected function tearDown(): void
    {
        $entityManager = static::getContainer()->get('doctrine')->getManager();
        if (!$entityManager->isOpen()) {
            $entityManager = static::getContainer()->get('doctrine')->resetManager();
        }
        foreach ($this->groupIds as $id) {
            $group = $entityManager->find(NodeGroup::class, $id);
            if ($group !== null) {
                $entityManager->remove($group);
            }
        }
        foreach ($this->memberProviderNodeIds as $providerNodeId) {
            $node = $entityManager->getRepository(Node::class)->findOneBy(['providerNodeId' => $providerNodeId]);
            if ($node !== null) {
                $entityManager->remove($node);
            }
        }
        $entityManager->flush();
        parent::tearDown();
    }

    private function loginEditor(KernelBrowser $client): void
    {
        $client->loginUser($this->createUser(self::EDITOR_EMAIL, ['editor']));
    }

    private function memberPayload(): array
    {
        $providerNodeId = 'n'.bin2hex(random_bytes(8));
        $this->memberProviderNodeIds[] = $providerNodeId;

        return ['providerNodeId' => $providerNodeId, 'latitude' => 54.791, 'longitude' => 9.437];
    }

    private function save(KernelBrowser $client, array $payload, int $status): array
    {
        $client->jsonRequest('POST', '/api/node-groups', $payload);
        self::assertResponseStatusCodeSame($status);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        if (isset($data['id'])) {
            $this->groupIds[] = $data['id'];
        }

        return $data;
    }

    /**
     * The street part's area is the corridor around the member line: a closed
     * GeoJSON ring whose points stay within the fixed width (2 m, about
     * 0.00002 degrees latitude) of the member coordinates.
     */
    private function assertAreaAroundMembers(array $data): void
    {
        self::assertSame('Polygon', $data['area']['type']);
        $ring = $data['area']['coordinates'][0];
        self::assertGreaterThan(3, \count($ring));
        self::assertSame($ring[0], $ring[\count($ring) - 1], 'the area ring is closed');

        foreach ($ring as $point) {
            $nearMember = false;
            foreach ($data['members'] as $member) {
                if (abs($point[0] - $member['longitude']) < 0.002 && abs($point[1] - $member['latitude']) < 0.002) {
                    $nearMember = true;
                    break;
                }
            }
            self::assertTrue($nearMember, 'every area point lies within the corridor width of a member');
        }
    }

    public function testCreateRelationWithMultipleNodes(): void
    {
        $client = static::createClient();
        $this->loginEditor($client);

        $data = $this->save($client, [
            'name' => 'Hochallee north part',
            'members' => [$this->memberPayload(), $this->memberPayload(), $this->memberPayload()],
            'tags' => ['surface' => 'cobblestone'],
        ], 201);

        self::assertSame('Hochallee north part', $data['name']);
        self::assertCount(3, $data['members']);
        self::assertSame('cobblestone', $data['tags']['surface']);
        self::assertSame(['surface' => 'cobblestone'], $data['observations']);
        self::assertSame($this->memberProviderNodeIds, array_column($data['members'], 'providerNodeId'));
        $this->assertAreaAroundMembers($data);
    }

    public function testStreetPartBetweenTwoNodes(): void
    {
        $client = static::createClient();
        $this->loginEditor($client);

        // The smallest useful street part: the stretch of a street between
        // two nodes.
        $data = $this->save($client, ['members' => [$this->memberPayload(), $this->memberPayload()], 'tags' => []], 201);

        self::assertCount(2, $data['members']);
        $this->assertAreaAroundMembers($data);
    }

    public function testCreateRequiresAtLeastTwoPoints(): void
    {
        $client = static::createClient();
        $this->loginEditor($client);

        $this->save($client, ['members' => [$this->memberPayload()], 'tags' => []], 422);
    }

    public function testSavingAgainAddsMembersAndReplacesOwnTags(): void
    {
        $client = static::createClient();
        $this->loginEditor($client);

        $first = $this->save($client, [
            'members' => [$this->memberPayload(), $this->memberPayload(), $this->memberPayload()],
            'tags' => ['surface' => 'cobblestone'],
        ], 201);

        // Repeated member references are deduplicated, and tag saves replace
        // the editor's own tags.
        $second = $this->save($client, [
            'id' => $first['id'],
            'name' => 'Hochallee south part',
            'members' => array_merge(
                array_map(static fn (array $member): array => ['id' => $member['id']], $first['members']),
                [$this->memberPayload()],
            ),
            'tags' => ['surface' => 'asphalt', 'lit' => 'yes'],
        ], 200);

        self::assertSame($first['id'], $second['id']);
        self::assertSame('Hochallee south part', $second['name']);
        self::assertCount(4, $second['members']);
        self::assertSame(['surface' => 'asphalt', 'lit' => 'yes'], $second['tags']);
    }

    public function testListShowsRelationsWithMembers(): void
    {
        $client = static::createClient();
        $this->loginEditor($client);

        $this->save($client, [
            'members' => [$this->memberPayload(), $this->memberPayload(), $this->memberPayload()],
            'tags' => ['surface' => 'cobblestone'],
        ], 201);

        $client->request('GET', '/api/node-groups');
        self::assertResponseIsSuccessful();

        $list = json_decode((string) $client->getResponse()->getContent(), true);
        $matching = array_values(array_filter(
            $list,
            fn (array $group): bool => array_intersect(array_column($group['members'], 'providerNodeId'), $this->memberProviderNodeIds) !== [],
        ));
        self::assertNotEmpty($matching);
        self::assertSame('cobblestone', $matching[0]['tags']['surface']);
        self::assertCount(3, $matching[0]['members']);
        $this->assertAreaAroundMembers($matching[0]);
    }

    public function testGroupWithoutApprovedTagsIsOnlyVisibleToItsCreator(): void
    {
        $client = static::createClient();
        $editorOne = $this->createUser(self::EDITOR_EMAIL, ['editor']);
        $editorTwo = $this->createUser('editor-two@example.test', ['editor']);

        $client->loginUser($editorOne);
        $group = $this->save($client, [
            'members' => [$this->memberPayload(), $this->memberPayload()],
            'tags' => ['surface' => 'cobblestone'],
        ], 201);

        $findGroup = function () use ($client, $group): ?array {
            $client->request('GET', '/api/node-groups');
            $list = json_decode((string) $client->getResponse()->getContent(), true);
            $matching = array_values(array_filter(
                $list,
                static fn (array $entry): bool => $entry['id'] === $group['id'],
            ));

            return $matching[0] ?? null;
        };

        // The creator sees the relation while its tags await approval.
        $listed = $findGroup();
        self::assertNotNull($listed);
        self::assertSame(['surface' => 'cobblestone'], $listed['observations']);

        // Another editor does not, until one tag is approved.
        $client->loginUser($editorTwo);
        self::assertNull($findGroup());

        $this->setGroupTagApproval($group['id'], 'surface', 'cobblestone', true);
        $listed = $findGroup();
        self::assertNotNull($listed);
        self::assertSame(['surface' => 'cobblestone'], $listed['tags']);
        self::assertSame([], $listed['observations']);
    }

    private function setGroupTagApproval(int $groupId, string $key, string $value, bool $approved): void
    {
        $entityManager = static::getContainer()->get('doctrine')->getManager();
        $group = $entityManager->find(NodeGroup::class, $groupId);
        if ($group === null) {
            return;
        }

        foreach ($group->getGroupTags() as $groupTag) {
            if ($groupTag->getTag()->getKey() === $key && $groupTag->getTag()->getValue() === $value) {
                $groupTag->setApproved($approved);
            }
        }
        $entityManager->flush();
    }

    public function testSavingRequiresAnEditor(): void
    {
        $client = static::createClient();

        $client->jsonRequest('POST', '/api/node-groups', [
            'members' => [$this->memberPayload(), $this->memberPayload(), $this->memberPayload()],
            'tags' => [],
        ]);
        self::assertResponseStatusCodeSame(401);
    }

    public function testUnknownRelationIdIsRejected(): void
    {
        $client = static::createClient();
        $this->loginEditor($client);

        $this->save($client, ['id' => 999999999, 'members' => [], 'tags' => []], 422);
    }
}
