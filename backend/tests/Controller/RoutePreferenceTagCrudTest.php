<?php

namespace App\Tests\Controller;

use App\Entity\RoutePreferenceTag;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class RoutePreferenceTagCrudTest extends WebTestCase
{
    use CreatesUsers;

    public function testAdminCanExposeATagAsRoutePreferenceSetting(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser('admin-route-preference@example.test', ['admin']), 'admin');

        $crawler = $client->request('GET', '/admin/route-preference-tag/new');

        self::assertResponseIsSuccessful();

        $tag = 'crud-'.bin2hex(random_bytes(8));
        $form = $crawler->selectButton('Create')->form();
        $form['RoutePreferenceTag[tag]'] = $tag;
        $client->submit($form);
        self::assertResponseRedirects();

        try {
            $client->followRedirect();

            self::assertResponseIsSuccessful();

            // The admin list shows the newly exposed tag.
            $client->request('GET', '/admin/route-preference-tag');
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('table', $tag);

            // The exposed tag is offered to users as a route preference.
            $client->request('GET', '/api/route-preference-tags');
            self::assertResponseIsSuccessful();
            self::assertContains($tag, json_decode((string) $client->getResponse()->getContent(), true)['tags']);
        } finally {
            $this->removeRoutePreferenceTag($tag);
        }
    }

    public function testEditorsAreDenied(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser('editor-route-preference@example.test', ['editor']), 'admin');

        $client->request('GET', '/admin/route-preference-tag');

        self::assertResponseStatusCodeSame(403);
    }

    private function removeRoutePreferenceTag(string $tag): void
    {
        if (!$this->getContainer()) {
            return;
        }

        $entityManager = static::getContainer()->get('doctrine')->getManager();
        $routePreferenceTag = $entityManager->getRepository(RoutePreferenceTag::class)->findOneBy(['tag' => $tag]);
        if ($routePreferenceTag !== null) {
            $entityManager->remove($routePreferenceTag);
            $entityManager->flush();
        }
    }
}
