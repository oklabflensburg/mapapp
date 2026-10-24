<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminDashboardTest extends WebTestCase
{
    use CreatesUsers;

    private function loginAdmin($client): void
    {
        $client->loginUser($this->createUser('admin-dashboard-test@example.test', ['admin']), 'admin');
    }

    public function testAdminDashboardRedirectsAnonymousVisitorsToTheLoginForm(): void
    {
        $client = static::createClient();
        $client->request('GET', '/admin');

        self::assertResponseRedirects('/admin/login');
    }

    public function testAdminDashboardShowsStyledDeniedPageForEditors(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser('editor-admin-test@example.test', ['editor']), 'admin');
        $client->request('GET', '/admin');

        self::assertResponseStatusCodeSame(403);
        self::assertSelectorExists('.auth-card');
        self::assertSelectorTextContains('.auth-error', 'does not have admin rights');
        self::assertSelectorExists('a[href="/admin/logout"]');
        self::assertSelectorExists('a[href="/admin/login"]');
    }

    public function testAdminDashboardIsRenderedForAdmins(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);
        $client->request('GET', '/admin');

        // The dashboard lands on the Users CRUD list.
        self::assertResponseRedirects();
        $client->followRedirect();

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('title', 'Users');
        // The sidebar links to the other CRUD sections.
        self::assertSelectorExists('a[href*="/admin/node"]');
        self::assertSelectorExists('a[href*="/admin/user-role"]');
    }

    public function testAdminCanListUsers(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);
        $client->request('GET', '/admin/user');

        self::assertResponseIsSuccessful();
    }
}
