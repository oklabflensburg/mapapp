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
        // The "User settings" section exposes tags as route preferences.
        self::assertSelectorExists('a[href*="/admin/route-preference-tag"]');
    }

    public function testAdminCanListUsers(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);
        $client->request('GET', '/admin/user');

        self::assertResponseIsSuccessful();
    }

    public function testTagBatchDeleteUsesTheBrowserOriginBehindATlsProxy(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);
        // Model an HTTPS browser request forwarded to PHP over HTTP.
        $crawler = $client->request('GET', '/admin/tag', server: [
            'HTTP_HOST' => 'map.hendrikgoebel.de',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ]);

        self::assertResponseIsSuccessful();
        $actions = $crawler->filter('[data-action-url*="/admin/tag/batch-delete"]');
        self::assertGreaterThan(0, $actions->count());
        foreach ($actions as $action) {
            self::assertStringStartsWith('/admin/tag/batch-delete', $action->getAttribute('data-action-url'));
        }
    }
}
