<?php

namespace App\Tests\Controller;

use App\Entity\DefaultObservationTag;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class DefaultObservationTagCrudTest extends WebTestCase
{
    use CreatesUsers;

    public function testAdminCanExposeATagAsDefaultObservationTag(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser('admin-default-observation@example.test', ['admin']), 'admin');

        $crawler = $client->request('GET', '/admin/default-observation-tag/new');

        self::assertResponseIsSuccessful();

        $tag = 'crud-'.bin2hex(random_bytes(8));
        $form = $crawler->selectButton('Create')->form();
        $form['DefaultObservationTag[tag]'] = $tag;
        $client->submit($form);
        self::assertResponseRedirects();

        try {
            $client->followRedirect();

            self::assertResponseIsSuccessful();

            // The admin list shows the newly exposed tag.
            $client->request('GET', '/admin/default-observation-tag');
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('table', $tag);

            // Without configured values the frontend gets the bare key.
            $this->assertApiTag($client, $tag, []);

            // Admins pick the values users can choose for the key; the edit
            // form edits the existing value entries of the collection.
            $entityManager = static::getContainer()->get('doctrine')->getManager();
            $entity = $entityManager->getRepository(DefaultObservationTag::class)->findOneBy(['tag' => $tag]);
            self::assertNotNull($entity);
            $entity->setDefaultValues(['yes']);
            $entityManager->flush();

            $crawler = $client->request('GET', '/admin/default-observation-tag/'.$entity->getId().'/edit');
            self::assertResponseIsSuccessful();

            $form = $crawler->selectButton('Save changes')->form();
            $form['DefaultObservationTag[defaultValues][0]'] = 'limited';
            $client->submit($form);
            self::assertResponseRedirects();

            // The changed value list is served to the frontend together
            // with the exposed tag.
            $this->assertApiTag($client, $tag, ['limited']);
        } finally {
            $this->removeDefaultObservationTag($tag);
        }
    }

    /**
     * @param list<string> $values
     */
    private function assertApiTag(object $client, string $tag, array $values): void
    {
        $client->request('GET', '/api/default-observation-tags');
        self::assertResponseIsSuccessful();

        $tags = json_decode((string) $client->getResponse()->getContent(), true)['tags'];
        $matching = array_values(array_filter(
            $tags,
            static fn (array $entry): bool => ($entry['tag'] ?? null) === $tag,
        ));

        self::assertSame([['tag' => $tag, 'values' => $values]], $matching);
    }

    public function testEditorsAreDenied(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser('editor-default-observation@example.test', ['editor']), 'admin');

        $client->request('GET', '/admin/default-observation-tag');

        self::assertResponseStatusCodeSame(403);
    }

    private function removeDefaultObservationTag(string $tag): void
    {
        if (!$this->getContainer()) {
            return;
        }

        $entityManager = static::getContainer()->get('doctrine')->getManager();
        $defaultObservationTag = $entityManager->getRepository(DefaultObservationTag::class)->findOneBy(['tag' => $tag]);
        if ($defaultObservationTag !== null) {
            $entityManager->remove($defaultObservationTag);
            $entityManager->flush();
        }
    }
}
