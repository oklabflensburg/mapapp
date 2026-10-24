<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AuthenticationTest extends WebTestCase
{
    use CreatesUsers;

    private const EDITOR_EMAIL = 'editor@example.test';
    private const ADMIN_EMAIL = 'admin@example.test';
    private const PASSWORD = 'correct horse battery staple';

    private function login(KernelBrowser $client, string $email, string $password): void
    {
        $client->jsonRequest('POST', '/api/login', ['email' => $email, 'password' => $password]);
    }

    public function testAnonymousUserCanViewNodesAndMe(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/nodes');

        self::assertResponseIsSuccessful();

        $client->request('GET', '/api/me');
        self::assertResponseIsSuccessful();
        self::assertSame(['user' => null], json_decode((string) $client->getResponse()->getContent(), true));
    }

    public function testAnonymousUserCannotSaveNodes(): void
    {
        $client = static::createClient();
        $client->jsonRequest('POST', '/api/nodes', ['providerNodeId' => 'n-anon', 'latitude' => 54.791, 'longitude' => 9.437, 'tags' => []]);

        self::assertResponseStatusCodeSame(401);
    }

    public function testJsonLoginAllowsEditorToSaveNodes(): void
    {
        $client = static::createClient();
        $this->createUser(self::EDITOR_EMAIL, ['editor']);

        $this->login($client, self::EDITOR_EMAIL, self::PASSWORD);
        self::assertResponseIsSuccessful();

        $client->request('GET', '/api/me');
        self::assertResponseIsSuccessful();
        $me = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame(self::EDITOR_EMAIL, $me['user']['email']);
        self::assertContains('ROLE_EDITOR', $me['user']['roles']);

        $client->jsonRequest('POST', '/api/nodes', ['provider' => 'local', 'providerNodeId' => 'n-editor-'.bin2hex(random_bytes(4)), 'latitude' => 54.791, 'longitude' => 9.437, 'tags' => []]);
        self::assertResponseStatusCodeSame(201);
    }

    public function testAdminInheritsEditorRightsThroughRoleHierarchy(): void
    {
        $client = static::createClient();
        $this->createUser(self::ADMIN_EMAIL, ['admin']);

        $this->login($client, self::ADMIN_EMAIL, self::PASSWORD);
        self::assertResponseIsSuccessful();

        $client->request('GET', '/api/me');
        $me = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertContains('ROLE_ADMIN', $me['user']['roles']);

        $client->jsonRequest('POST', '/api/nodes', ['provider' => 'local', 'providerNodeId' => 'n-admin-'.bin2hex(random_bytes(4)), 'latitude' => 54.791, 'longitude' => 9.437, 'tags' => []]);
        self::assertResponseStatusCodeSame(201);
    }

    public function testJsonLoginRejectsWrongPassword(): void
    {
        $client = static::createClient();
        $this->createUser(self::EDITOR_EMAIL, ['editor']);

        $this->login($client, self::EDITOR_EMAIL, 'wrong password');
        self::assertResponseStatusCodeSame(401);
    }

    public function testLogoutEndsTheSession(): void
    {
        $client = static::createClient();
        $this->createUser(self::EDITOR_EMAIL, ['editor']);

        $this->login($client, self::EDITOR_EMAIL, self::PASSWORD);
        self::assertResponseIsSuccessful();

        $client->request('POST', '/api/logout');
        self::assertResponseIsSuccessful();

        $client->jsonRequest('POST', '/api/nodes', ['provider' => 'local', 'providerNodeId' => 'n-logged-out', 'latitude' => 54.791, 'longitude' => 9.437, 'tags' => []]);
        self::assertResponseStatusCodeSame(401);
    }
}
