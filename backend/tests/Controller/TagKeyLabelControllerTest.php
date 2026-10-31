<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class TagKeyLabelControllerTest extends WebTestCase
{
    public function testServesKeysAndContextualValuesPerLocale(): void
    {
        $client = static::createClient();
        foreach (['en' => ['amenity', 'Yes'], 'de' => ['Einrichtung', 'Ja']] as $locale => [$key, $value]) {
            $client->request('GET', '/api/i18n/tag-keys/'.$locale);
            self::assertResponseIsSuccessful();
            $data = json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame($key, $data['labels']['amenity']);
            self::assertSame($value, $data['values']['["wheelchair","yes"]']);
            self::assertArrayNotHasKey('["name","yes"]', $data['values']);
        }
    }

    public function testUnknownLocaleIsRejected(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/i18n/tag-keys/fr');
        self::assertResponseStatusCodeSame(404);
    }

    public function testEtagServesNotModifiedAndDiffersBetweenLocales(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/i18n/tag-keys/en');
        self::assertResponseIsSuccessful();
        $etag = $client->getResponse()->getEtag();
        self::assertNotNull($etag);
        $client->request('GET', '/api/i18n/tag-keys/en', [], [], ['HTTP_IF_NONE_MATCH' => $etag]);
        self::assertResponseStatusCodeSame(304);
        $client->request('GET', '/api/i18n/tag-keys/de', [], [], ['HTTP_IF_NONE_MATCH' => $etag]);
        self::assertResponseIsSuccessful();
        self::assertNotSame($etag, $client->getResponse()->getEtag());
    }
}
