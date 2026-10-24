<?php

namespace App\Tests\Controller;

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
        self::assertSame(['priorities' => ['stairs' => 'try_to_avoid']], json_decode((string) $client->getResponse()->getContent(), true));

        $client->request('GET', '/api/tag-priorities');
        self::assertResponseIsSuccessful();
        self::assertSame(['priorities' => ['stairs' => 'try_to_avoid']], json_decode((string) $client->getResponse()->getContent(), true));

        // Saving again updates the stored priority instead of duplicating it.
        $client->jsonRequest('PUT', '/api/tag-priorities', ['priorities' => ['stairs' => 'strongly_prefer']]);
        self::assertResponseIsSuccessful();
        self::assertSame(['priorities' => ['stairs' => 'strongly_prefer']], json_decode((string) $client->getResponse()->getContent(), true));
    }

    public function testNeutralPriorityIsTheDefaultAndRemovesStoredPreferences(): void
    {
        $client = static::createClient();
        $user = $this->loginPlainUser($client, 'neutral-priorities@example.test');

        $client->request('GET', '/api/tag-priorities');
        self::assertResponseIsSuccessful();
        self::assertSame(['priorities' => ['stairs' => 'neutral']], json_decode((string) $client->getResponse()->getContent(), true));

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
        self::assertSame(['priorities' => ['stairs' => 'neutral']], json_decode((string) $client->getResponse()->getContent(), true));
    }

    public function testInvalidLevelIsRejected(): void
    {
        $client = static::createClient();
        $this->loginPlainUser($client, 'invalid-priorities@example.test');

        $client->jsonRequest('PUT', '/api/tag-priorities', ['priorities' => ['stairs' => 'unknown_level']]);

        self::assertResponseStatusCodeSame(422);
    }
}
