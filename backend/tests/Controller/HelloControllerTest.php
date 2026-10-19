<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HelloControllerTest extends WebTestCase
{
    public function testHelloReturnsGreeting(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/hello');

        self::assertResponseIsSuccessful();
        self::assertJson($client->getResponse()->getContent());
        self::assertSame(
            ['message' => 'Hello from Symfony!'],
            json_decode($client->getResponse()->getContent(), true),
        );
    }
}
