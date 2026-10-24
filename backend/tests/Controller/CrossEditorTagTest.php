<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Reproduction: can one editor overwrite another editor's unapproved tags?
 */
final class CrossEditorTagTest extends WebTestCase
{
    use CreatesUsers;

    /** @var list<int> */
    private array $nodeIds = [];

    protected function tearDown(): void
    {
        $manager = static::getContainer()->get('doctrine')->getManager();
        if (!$manager->isOpen()) {
            $manager = static::getContainer()->get('doctrine')->resetManager();
        }
        foreach (array_unique($this->nodeIds) as $id) {
            $node = $manager->find(\App\Entity\Node::class, $id);
            if ($node !== null) {
                $manager->remove($node);
            }
        }
        $manager->flush();
        parent::tearDown();
    }

    private function login(KernelBrowser $client, string $email): void
    {
        $client->loginUser($this->createUser($email, ['editor']));
    }

    private function save(KernelBrowser $client, array $payload, int $status = 201): array
    {
        $client->jsonRequest('POST', '/api/nodes', $payload);
        self::assertResponseStatusCodeSame($status);
        $data = json_decode($client->getResponse()->getContent(), true);
        if (isset($data['id'])) {
            $this->nodeIds[] = $data['id'];
            array_push($this->nodeIds, ...$data['parentIds']);
        }

        return $data;
    }

    public function testEditorCannotOverwriteOtherEditorsUnapprovedTags(): void
    {
        $identity = bin2hex(random_bytes(16));

        // Editor2 creates the node with an unapproved tag.
        $client = static::createClient();
        $this->login($client, 'editor2@example.test');
        $node = $this->save($client, ['provider' => 'local', 'providerNodeId' => $identity, 'latitude' => 54.791, 'longitude' => 9.437, 'tags' => ['name' => 'Editors2Place']]);
        self::assertSame(['name' => 'Editors2Place'], $node['tags']);
        self::assertSame(['name' => 'Editors2Place'], $node['observations']);
        $client->getCookieJar()->clear();
        static::ensureKernelShutdown();

        // Editor1 saves the same node with a different value for the same key.
        $client2 = static::createClient();
        $this->login($client2, 'editor1@example.test');
        $saved = $this->save($client2, ['provider' => 'local', 'providerNodeId' => $identity, 'latitude' => 54.791, 'longitude' => 9.437, 'tags' => ['name' => 'Editors1Place']], 200);

        // Editor1 must not see or replace editor2's unapproved tag.
        self::assertSame(['name' => 'Editors1Place'], $saved['tags']);

        // Editor2's unapproved tag must be untouched.
        $manager = static::getContainer()->get('doctrine')->getManager();
        $nodeEntity = $manager->find(\App\Entity\Node::class, $node['id']);
        $byUser = [];
        foreach ($nodeEntity->getNodeTags() as $nodeTag) {
            $byUser[$nodeTag->getCreatedByUser()->getEmail()][] = $nodeTag->getTag()->getKey().'='.$nodeTag->getTag()->getValue();
        }
        self::assertSame(['name=Editors2Place'], $byUser['editor2@example.test'] ?? null);
        self::assertSame(['name=Editors1Place'], $byUser['editor1@example.test'] ?? null);
    }
}
