<?php

namespace App\Tests\Controller;

use App\Entity\RoutePreferenceTag;
use App\Entity\User;
use App\Entity\UserTagPriority;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class TagPriorityControllerTest extends WebTestCase
{
    use CreatesUsers;

    private function loginPlainUser(KernelBrowser $client, string $email): User
    {
        $user = $this->createUser($email, []);
        $client->loginUser($user);

        return $user;
    }

    public function testAnonymousRequestsAreRejected(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/tag-priorities');
        self::assertResponseStatusCodeSame(401);

        $client->jsonRequest('PUT', '/api/tag-priorities', ['priorities' => ['stairs' => 'avoid']]);
        self::assertResponseStatusCodeSame(401);
    }

    public function testSaveAndGetRoundTrip(): void
    {
        $client = static::createClient();
        $this->loginPlainUser($client, 'priorities@example.test');

        $client->jsonRequest('PUT', '/api/tag-priorities', ['priorities' => ['stairs' => 'try_to_avoid']]);

        self::assertResponseIsSuccessful();
        self::assertSame(['priorities' => ['stairs' => 'try_to_avoid', 'toilets' => 'neutral']], json_decode((string) $client->getResponse()->getContent(), true));

        $client->request('GET', '/api/tag-priorities');
        self::assertResponseIsSuccessful();
        self::assertSame(['priorities' => ['stairs' => 'try_to_avoid', 'toilets' => 'neutral']], json_decode((string) $client->getResponse()->getContent(), true));

        // Saving again updates the stored priority instead of duplicating it.
        $client->jsonRequest('PUT', '/api/tag-priorities', ['priorities' => ['stairs' => 'strongly_prefer']]);
        self::assertResponseIsSuccessful();
        self::assertSame(['priorities' => ['stairs' => 'strongly_prefer', 'toilets' => 'neutral']], json_decode((string) $client->getResponse()->getContent(), true));
    }

    public function testNeutralPriorityIsTheDefaultAndRemovesStoredPreferences(): void
    {
        $client = static::createClient();
        $user = $this->loginPlainUser($client, 'neutral-priorities@example.test');

        $client->request('GET', '/api/tag-priorities');
        self::assertResponseIsSuccessful();
        self::assertSame(['priorities' => ['stairs' => 'neutral', 'toilets' => 'neutral']], json_decode((string) $client->getResponse()->getContent(), true));

        $client->jsonRequest('PUT', '/api/tag-priorities', ['priorities' => ['stairs' => 'prefer']]);
        self::assertResponseIsSuccessful();

        $client->jsonRequest('PUT', '/api/tag-priorities', ['priorities' => ['stairs' => 'neutral']]);
        self::assertResponseIsSuccessful();

        $repository = static::getContainer()->get('doctrine')->getRepository(UserTagPriority::class);
        self::assertSame([], $repository->findAllForUser($user));
    }

    public function testPrioritiesArePerUser(): void
    {
        $client = static::createClient();
        $this->loginPlainUser($client, 'owner-priorities@example.test');

        $client->jsonRequest('PUT', '/api/tag-priorities', ['priorities' => ['stairs' => 'avoid']]);
        self::assertResponseIsSuccessful();

        $this->loginPlainUser($client, 'other-priorities@example.test');
        $client->request('GET', '/api/tag-priorities');

        self::assertResponseIsSuccessful();
        self::assertSame(['priorities' => ['stairs' => 'neutral', 'toilets' => 'neutral']], json_decode((string) $client->getResponse()->getContent(), true));
    }

    public function testInvalidLevelIsRejected(): void
    {
        $client = static::createClient();
        $this->loginPlainUser($client, 'invalid-priorities@example.test');

        $client->jsonRequest('PUT', '/api/tag-priorities', ['priorities' => ['stairs' => 'unknown_level']]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testRoutePreferenceTagsEndpointIsPublicAndSorted(): void
    {
        $client = static::createClient();

        $tag = $this->exposeTag('benches');

        try {
            $client->request('GET', '/api/route-preference-tags');

            self::assertResponseIsSuccessful();
            $tags = json_decode((string) $client->getResponse()->getContent(), true)['tags'];
            self::assertContains($tag, $tags);
            self::assertContains('stairs', $tags);
            self::assertContains('toilets', $tags);
            self::assertSame($tags, array_values($tags));
            self::assertSame($tags, array_map('strval', $tags));
        } finally {
            $this->removeExposedTag($tag);
        }
    }

    public function testNewlyExposedTagCanBePrioritizedAndDisappearsWhenRemoved(): void
    {
        $client = static::createClient();
        $this->loginPlainUser($client, 'dynamic-priorities@example.test');

        $tag = $this->exposeTag('benches-'.bin2hex(random_bytes(4)));

        try {
            $client->request('GET', '/api/tag-priorities');
            self::assertResponseIsSuccessful();
            $priorities = json_decode((string) $client->getResponse()->getContent(), true)['priorities'];
            self::assertSame('neutral', $priorities[$tag]);

            $client->jsonRequest('PUT', '/api/tag-priorities', ['priorities' => [$tag => 'prefer']]);
            self::assertResponseIsSuccessful();

            $client->request('GET', '/api/tag-priorities');
            $priorities = json_decode((string) $client->getResponse()->getContent(), true)['priorities'];
            self::assertSame('prefer', $priorities[$tag]);
        } finally {
            $this->removeExposedTag($tag);
        }

        // Once the tag is no longer exposed, it leaves the priorities map
        // (the stored preference survives and revives if it returns).
        $client->request('GET', '/api/tag-priorities');
        self::assertResponseIsSuccessful();
        $priorities = json_decode((string) $client->getResponse()->getContent(), true)['priorities'];
        self::assertArrayNotHasKey($tag, $priorities);

        $this->exposeTag($tag);
        try {
            $client->request('GET', '/api/tag-priorities');
            $priorities = json_decode((string) $client->getResponse()->getContent(), true)['priorities'];
            self::assertSame('prefer', $priorities[$tag]);
        } finally {
            $this->removeExposedTag($tag);
        }
    }

    public function testPrioritiesForTagsThatAreNotExposedAreIgnored(): void
    {
        $client = static::createClient();
        $this->loginPlainUser($client, 'unknown-tag-priorities@example.test');

        $client->jsonRequest('PUT', '/api/tag-priorities', ['priorities' => ['not_a_setting' => 'avoid']]);

        self::assertResponseIsSuccessful();
        $priorities = json_decode((string) $client->getResponse()->getContent(), true)['priorities'];
        self::assertArrayNotHasKey('not_a_setting', $priorities);
    }

    private function exposeTag(string $tag): string
    {
        $entityManager = static::getContainer()->get('doctrine')->getManager();
        $routePreferenceTag = new RoutePreferenceTag();
        $routePreferenceTag->setTag($tag);
        $entityManager->persist($routePreferenceTag);
        $entityManager->flush();

        return $tag;
    }

    private function removeExposedTag(string $tag): void
    {
        $entityManager = static::getContainer()->get('doctrine')->getManager();
        $routePreferenceTag = $entityManager->getRepository(RoutePreferenceTag::class)->findOneBy(['tag' => $tag]);
        if ($routePreferenceTag !== null) {
            $entityManager->remove($routePreferenceTag);
            $entityManager->flush();
        }
    }
}
