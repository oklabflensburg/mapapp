<?php

namespace App\Tests\Controller;

use App\Entity\TagKeyLabel;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class TagKeyLabelControllerTest extends WebTestCase
{
    public function testServesCatalogLabelsPerLocale(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/i18n/tag-keys/en');
        self::assertResponseIsSuccessful();
        $en = $this->labels($client);
        // Keys the catalogue translates, and keys it only prettifies.
        self::assertSame('tactile paving', $en['tactile_paving'] ?? null);
        self::assertSame('amenity', $en['amenity'] ?? null);

        $client->request('GET', '/api/i18n/tag-keys/de');
        self::assertResponseIsSuccessful();
        $de = $this->labels($client);
        self::assertSame('Einrichtung', $de['amenity'] ?? null);
        self::assertSame('Rollstuhlzugänglichkeit', $de['wheelchair'] ?? null);
    }

    public function testUnknownLocaleIsRejected(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/i18n/tag-keys/fr');
        self::assertResponseStatusCodeSame(404);
    }

    public function testAdminManagedLabelOverridesTheCatalogue(): void
    {
        $client = static::createClient();
        $this->removeLabels('amenity');

        $manager = static::getContainer()->get('doctrine')->getManager();
        $label = new TagKeyLabel();
        $label->setKey('amenity');
        $label->setLocale('de');
        $label->setLabel('Verwaltung');
        $manager->persist($label);
        $manager->flush();

        try {
            $client->request('GET', '/api/i18n/tag-keys/de');
            self::assertResponseIsSuccessful();
            self::assertSame('Verwaltung', $this->labels($client)['amenity'] ?? null);

            // Only the label's own locale is overridden.
            $client->request('GET', '/api/i18n/tag-keys/en');
            self::assertResponseIsSuccessful();
            self::assertSame('amenity', $this->labels($client)['amenity'] ?? null);
        } finally {
            // The kernel reboots on each request, so the persisted entity is
            // detached; remove whatever is stored by key.
            $this->removeLabels('amenity');
        }
    }

    public function testEtagServesNotModifiedForUnchangedLabels(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/i18n/tag-keys/en');
        self::assertResponseIsSuccessful();
        $etag = $client->getResponse()->getEtag();
        self::assertNotNull($etag);

        $client->request('GET', '/api/i18n/tag-keys/en', [], [], ['HTTP_IF_NONE_MATCH' => $etag]);
        self::assertResponseStatusCodeSame(304);

        // Adding a label changes the signature, so the client re-fetches.
        $this->removeLabels('etag-check');
        $manager = static::getContainer()->get('doctrine')->getManager();
        $label = new TagKeyLabel();
        $label->setKey('etag-check');
        $label->setLocale('en');
        $label->setLabel('Etag check');
        $manager->persist($label);
        $manager->flush();

        try {
            $client->request('GET', '/api/i18n/tag-keys/en', [], [], ['HTTP_IF_NONE_MATCH' => $etag]);
            self::assertResponseIsSuccessful();
        } finally {
            $this->removeLabels('etag-check');
        }
    }

    /**
     * Label rows survive a failed test run, so each test clears its keys
     * instead of relying on the previous run's cleanup.
     */
    private function removeLabels(string $key): void
    {
        $manager = static::getContainer()->get('doctrine')->getManager();
        foreach ($manager->getRepository(TagKeyLabel::class)->findBy(['key' => $key]) as $label) {
            $manager->remove($label);
        }
        $manager->flush();
    }

    /** @return array<string, string> */
    private function labels(KernelBrowser $client): array
    {
        $data = json_decode((string) $client->getResponse()->getContent(), true);

        return \is_array($data) ? ($data['labels'] ?? []) : [];
    }
}
