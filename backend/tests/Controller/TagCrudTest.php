<?php

namespace App\Tests\Controller;

use App\Entity\Tag;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class TagCrudTest extends WebTestCase
{
    use CreatesUsers;

    public function testTagEditPageHasNoGlobalApprovalToggle(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser('admin-tag-crud@example.test', ['admin']), 'admin');

        $entityManager = static::getContainer()->get('doctrine')->getManager();
        $tag = new Tag();
        $tag->setKey('access');
        $tag->setValue('tag-crud-'.bin2hex(random_bytes(8)));
        $entityManager->persist($tag);
        $entityManager->flush();

        try {
            $crawler = $client->request('GET', '/admin/tag/'.$tag->getId().'/edit');

            self::assertResponseIsSuccessful();
            // Approval is per tag and per node/relation now (see NodeTag and
            // NodeGroupTag), so the shared tag has no approval toggle.
            self::assertCount(0, $crawler->filter('form input[name*="[approved]"]'));

            // The key and value stay editable.
            $renamed = 'tag-crud-renamed-'.bin2hex(random_bytes(8));
            $form = $crawler->selectButton('Save changes')->form();
            $form['Tag[value]'] = $renamed;
            $client->submit($form);
            self::assertResponseRedirects();

            self::assertSame($renamed, $entityManager->find(Tag::class, $tag->getId())->getValue());
        } finally {
            $manager = static::getContainer()->get('doctrine')->getManager();
            $manager->remove($manager->find(Tag::class, $tag->getId()));
            $manager->flush();
        }
    }
}
