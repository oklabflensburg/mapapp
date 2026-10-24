<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminLoginFormTest extends WebTestCase
{
    use CreatesUsers;

    private const ADMIN_EMAIL = 'admin-login-form@example.test';
    private const ADMIN_PASSWORD = 'correct horse battery staple';

    private function fetchCsrfToken($client): string
    {
        $crawler = $client->getCrawler();

        return $crawler->filter('input[name="_csrf_token"]')->attr('value');
    }

    public function testLoginFormRendersForAnonymousVisitors(): void
    {
        $client = static::createClient();
        $client->request('GET', '/admin/login');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[name="email"]');
        self::assertSelectorExists('input[name="password"]');
        self::assertSelectorExists('input[name="_csrf_token"]');
    }

    public function testLoginFormRejectsWrongCredentials(): void
    {
        $client = static::createClient();
        $this->createUser(self::ADMIN_EMAIL, ['admin'], self::ADMIN_PASSWORD);
        $client->request('GET', '/admin/login');
        $csrfToken = $this->fetchCsrfToken($client);

        $client->request('POST', '/admin/login', [
            'email' => self::ADMIN_EMAIL,
            'password' => 'wrong password',
            '_csrf_token' => $csrfToken,
        ]);

        self::assertResponseRedirects('/admin/login');
        $client->followRedirect();

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.auth-error', 'Invalid credentials.');
    }

    public function testLoginFormAuthenticatesAdminsAndOpensTheDashboard(): void
    {
        $client = static::createClient();
        $this->createUser(self::ADMIN_EMAIL, ['admin'], self::ADMIN_PASSWORD);
        $client->request('GET', '/admin/login');
        $csrfToken = $this->fetchCsrfToken($client);

        $client->request('POST', '/admin/login', [
            'email' => self::ADMIN_EMAIL,
            'password' => self::ADMIN_PASSWORD,
            '_csrf_token' => $csrfToken,
        ]);

        self::assertResponseRedirects('/admin');
        $client->followRedirect();
        $client->followRedirect();

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('title', 'Users');
    }
}
