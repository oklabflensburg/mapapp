<?php

namespace App\Tests\Controller;

use App\Entity\Node;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class NodeControllerTest extends WebTestCase
{
    use CreatesUsers;

    private const EDITOR_EMAIL = 'editor@example.test';

    private function loginEditor(KernelBrowser $client): void
    {
        $client->loginUser($this->createUser(self::EDITOR_EMAIL, ['editor']));
    }

    private const PROVIDER_NODE_ID = 'n26013095';

    private function removeNodeUnderTest(): void
    {
        $entityManager = static::getContainer()->get('doctrine')->getManager();
        $node = $entityManager->getRepository(Node::class)
            ->findOneBy(['providerNodeId' => self::PROVIDER_NODE_ID]);

        if ($node !== null) {
            $entityManager->remove($node);
            $entityManager->flush();
        }
    }

    public function testCreateUserNode(): void
    {
        $client = static::createClient();
        $this->loginEditor($client);
        $this->removeNodeUnderTest();
        $client->request('POST', '/api/nodes', [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode([
            'providerNodeId' => self::PROVIDER_NODE_ID,
            'latitude' => 54.791,
            'longitude' => 9.437,
            'tags' => ['access' => 'yes', 'note' => 'step-free entrance'],
        ]));

        self::assertResponseIsSuccessful();
        self::assertSame(201, $client->getResponse()->getStatusCode());

        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame(self::PROVIDER_NODE_ID, $data['providerNodeId']);
        self::assertSame(54.791, $data['latitude']);
        self::assertSame(9.437, $data['longitude']);
        self::assertSame(['access' => 'yes', 'note' => 'step-free entrance'], $data['tags']);
    }

    public function testSavingSameNodeAgainUpdatesItInsteadOfDuplicating(): void
    {
        $client = static::createClient();
        $this->loginEditor($client);
        $this->removeNodeUnderTest();
        $payload = static fn (array $tags): string => (string) json_encode([
            'providerNodeId' => self::PROVIDER_NODE_ID,
            'latitude' => 54.791,
            'longitude' => 9.437,
            'tags' => $tags,
        ]);

        $client->request('POST', '/api/nodes', [], [], ['CONTENT_TYPE' => 'application/json'], $payload(['access' => 'yes']));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $first = json_decode((string) $client->getResponse()->getContent(), true);

        $client->request('POST', '/api/nodes', [], [], ['CONTENT_TYPE' => 'application/json'], $payload(['access' => 'no', 'note' => 'remodelled']));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $second = json_decode((string) $client->getResponse()->getContent(), true);

        self::assertSame($first['id'], $second['id']);
        self::assertSame(['access' => 'no', 'note' => 'remodelled'], $second['tags']);

        $client->request('GET', '/api/nodes');
        self::assertResponseIsSuccessful();

        $list = json_decode((string) $client->getResponse()->getContent(), true);
        $matching = array_values(array_filter(
            $list,
            static fn (array $node): bool => $node['providerNodeId'] === self::PROVIDER_NODE_ID,
        ));
        self::assertCount(1, $matching);
    }

    public function testRejectsOutOfRangeLatitude(): void
    {
        $client = static::createClient();
        $this->loginEditor($client);
        $this->removeNodeUnderTest();
        $client->request('POST', '/api/nodes', [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode([
            'providerNodeId' => self::PROVIDER_NODE_ID,
            'latitude' => 200.0,
            'longitude' => 9.437,
            'tags' => [],
        ]));

        self::assertSame(422, $client->getResponse()->getStatusCode());
    }

    public function testEachEditorSeesOnlyOwnObservations(): void
    {
        $client = static::createClient();
        $this->removeNodeUnderTest();
        $this->setTagApproval('note', 'entrance from the north', false);

        $editorOne = $this->createUser(self::EDITOR_EMAIL, ['editor']);
        $editorTwo = $this->createUser('editor-two@example.test', ['editor']);

        $client->loginUser($editorOne);
        $client->request('POST', '/api/nodes', [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode([
            'providerNodeId' => self::PROVIDER_NODE_ID,
            'latitude' => 54.791,
            'longitude' => 9.437,
            'tags' => ['note' => 'entrance from the north'],
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode());

        $client->loginUser($editorTwo);
        $client->request('POST', '/api/nodes', [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode([
            'providerNodeId' => self::PROVIDER_NODE_ID,
            'latitude' => 54.791,
            'longitude' => 9.437,
            'tags' => ['comment' => 'steep stairs'],
        ]));
        self::assertSame(200, $client->getResponse()->getStatusCode());

        $findNodeUnderTest = function () use ($client): ?array {
            $client->request('GET', '/api/nodes');
            $list = json_decode((string) $client->getResponse()->getContent(), true);
            $matching = array_values(array_filter(
                $list,
                static fn (array $node): bool => $node['providerNodeId'] === self::PROVIDER_NODE_ID,
            ));

            return $matching[0] ?? null;
        };

        $client->loginUser($editorOne);
        $node = $findNodeUnderTest();
        self::assertNotNull($node);
        self::assertSame(['note' => 'entrance from the north'], $node['tags']);
        self::assertSame(['note' => 'entrance from the north'], $node['observations']);

        // The node still has no approved tag, so only its creator sees it.
        $client->loginUser($editorTwo);
        self::assertNull($findNodeUnderTest());

        $this->setTagApproval('note', 'entrance from the north', true);
        $node = $findNodeUnderTest();
        self::assertNotNull($node);
        // The tag map's key order follows the database rows and is not part
        // of the API contract.
        self::assertEqualsCanonicalizing(['note' => 'entrance from the north', 'comment' => 'steep stairs'], $node['tags']);
        self::assertSame(['comment' => 'steep stairs'], $node['observations']);
    }

    public function testAnonymousVisitorsSeeNoNodesWithoutApprovedTags(): void
    {
        $client = static::createClient();
        $this->removeNodeUnderTest();
        $this->setTagApproval('note', 'anonymous entrance', false);

        $client->loginUser($this->createUser(self::EDITOR_EMAIL, ['editor']));
        $client->request('POST', '/api/nodes', [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode([
            'providerNodeId' => self::PROVIDER_NODE_ID,
            'latitude' => 54.791,
            'longitude' => 9.437,
            'tags' => ['note' => 'anonymous entrance'],
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode());

        // Dropping the session cookies makes the client anonymous again.
        $client->getCookieJar()->clear();
        $client->request('GET', '/api/nodes');
        $list = json_decode((string) $client->getResponse()->getContent(), true);
        $matching = array_values(array_filter(
            $list,
            static fn (array $node): bool => $node['providerNodeId'] === self::PROVIDER_NODE_ID,
        ));

        self::assertSame([], $matching);
    }

    private function setTagApproval(string $key, string $value, bool $approved): void
    {
        // Approval is per tag and per node: it is set on the row linking the
        // tag to the node under test, not on the shared tag.
        $entityManager = static::getContainer()->get('doctrine')->getManager();
        $node = $entityManager->getRepository(Node::class)->findOneBy(['providerNodeId' => self::PROVIDER_NODE_ID]);

        if ($node === null) {
            return;
        }

        foreach ($node->getNodeTags() as $nodeTag) {
            if ($nodeTag->getTag()->getKey() === $key && $nodeTag->getTag()->getValue() === $value) {
                $nodeTag->setApproved($approved);
            }
        }
        $entityManager->flush();
    }
}
