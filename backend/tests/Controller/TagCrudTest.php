<?php

namespace App\Tests\Controller;

use App\Entity\Tag;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class TagCrudTest extends WebTestCase
{
    use CreatesUsers;

    private function createTag(): Tag
    {
        $entityManager = static::getContainer()->get('doctrine')->getManager();
        $tag = new Tag();
        $tag->setKey('access');
        $tag->setValue('tag-crud-'.bin2hex(random_bytes(8)));
        $tag->setApproved(false);
        $entityManager->persist($tag);
        $entityManager->flush();

        return $tag;
    }

    public function testAdminCanToggleApproval(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser('admin-tag-crud@example.test', ['admin']), 'admin');
        $tag = $this->createTag();

        $crawler = $client->request('GET', '/admin/tag/'.$tag->getId().'/edit');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('form input[type="checkbox"][name*="[approved]"]'));

        $form = $crawler->selectButton('Save changes')->form();
        $form['Tag[approved]']->tick();
        $client->submit($form);
        self::assertResponseRedirects();

        $entityManager = static::getContainer()->get('doctrine')->getManager();
        $tag = $entityManager->find(Tag::class, $tag->getId());
        self::assertTrue($tag->isApproved());
    }
}
