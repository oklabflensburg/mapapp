<?php

namespace App\Tests\Controller;

use App\Entity\Node;
use App\Entity\Tag;
use App\Service\TagTranslationCatalog;
use App\Service\TagTranslationCatalogEditor;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ObservationTranslationTest extends WebTestCase
{
    use CreatesUsers;

    private string $directory;
    private array $payload;
    private int $nodeId;
    private string $key;
    private string $value = 'rough/ü<&';

    private function submitObservation(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();
        $suffix = bin2hex(random_bytes(8));
        $this->directory = sys_get_temp_dir().'/observation-translations-'.$suffix;
        mkdir($this->directory);
        foreach (['en', 'de'] as $locale) {
            copy(\dirname(__DIR__).'/Fixtures/tag-translations/tags.'.$locale.'.xlf', $this->directory.'/tags.'.$locale.'.xlf');
        }
        static::getContainer()->set(TagTranslationCatalog::class, new TagTranslationCatalog($this->directory));
        $client->loginUser($this->createUser('observation-translator@example.test', ['editor']));
        $this->key = 'test_surface_'.$suffix;
        $this->payload = [
            'provider' => 'local', 'providerNodeId' => 'translation-node-'.$suffix,
            'latitude' => 54.791, 'longitude' => 9.437,
            'tags' => [$this->key => $this->value], 'translationLocale' => 'de',
            'translations' => [[
                'key' => $this->key, 'value' => $this->value,
                'keyTranslation' => 'Oberfläche & <Belag>', 'valueTranslation' => 'Rau',
            ]],
        ];
        $client->jsonRequest('POST', '/api/nodes', $this->payload);
        self::assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->nodeId = $data['id'];
        self::assertSame('Rau', $data['observationTranslations'][$this->key]['de']['valueTranslation']);

        return $client;
    }

    private function review(KernelBrowser $client): \Symfony\Component\DomCrawler\Crawler
    {
        $client->loginUser($this->createUser('observation-translation-reviewer@example.test', ['admin']), 'admin');
        $crawler = $client->request('GET', '/admin/pending-observation/'.$this->nodeId.'/edit');
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    protected function tearDown(): void
    {
        if (isset($this->directory)) {
            $manager = static::getContainer()->get('doctrine')->getManager();
            if ($manager->isOpen()) {
                $manager->clear();
                if (isset($this->nodeId) && ($node = $manager->find(Node::class, $this->nodeId))) {
                    $manager->remove($node);
                    $manager->flush();
                }
                $manager->clear();
                if ($tag = $manager->getRepository(Tag::class)->findOneBy(['key' => $this->key])) {
                    $manager->remove($tag);
                    $manager->flush();
                }
            }
            foreach (glob($this->directory.'/*') as $path) {
                unlink($path);
            }
            rmdir($this->directory);
        }
        parent::tearDown();
    }

    public function testTranslationsRemainTagPropertiesUntilReviewedAndApproved(): void
    {
        $client = $this->submitObservation();
        $german = file_get_contents($this->directory.'/tags.de.xlf');
        $english = file_get_contents($this->directory.'/tags.en.xlf');
        self::assertStringNotContainsString($this->key, $german);
        $client->request('GET', '/api/nodes');
        self::assertResponseIsSuccessful();
        $nodes = json_decode($client->getResponse()->getContent(), true);
        $saved = array_values(array_filter($nodes, fn ($node) => $node['id'] === $this->nodeId))[0];
        self::assertSame('Rau', $saved['observationTranslations'][$this->key]['de']['valueTranslation']);
        $client->request('GET', '/api/i18n/tag-keys/de');
        self::assertArrayNotHasKey($this->key, json_decode($client->getResponse()->getContent(), true)['labels']);

        $crawler = $this->review($client);
        $form = $crawler->selectButton('Save changes')->form();
        $name = $form->getName();
        self::assertSame('Oberfläche & <Belag>', $form[$name.'[de][keyTranslation]']->getValue());
        self::assertSame('Rau', $form[$name.'[de][valueTranslation]']->getValue());
        self::assertSame($this->key, $form[$name.'[key]']->getValue());
        self::assertSame($this->value, $form[$name.'[value]']->getValue());
        $originalKey = $this->key;
        $reviewedKey = $this->key.'_reviewed';
        $form[$name.'[key]'] = $reviewedKey;
        $form[$name.'[value]'] = 'reviewed/raw/ü';
        $form[$name.'[de][valueTranslation]'] = 'Rauer Belag';
        $client->submit($form);
        self::assertResponseRedirects();
        $this->key = $reviewedKey;
        $this->value = 'reviewed/raw/ü';
        self::assertSame($german, file_get_contents($this->directory.'/tags.de.xlf'));
        $crawler = $this->review($client);
        $form = $crawler->selectButton('Approve')->form();
        self::assertSame($this->key, $form[$name.'[key]']->getValue());
        self::assertSame($this->value, $form[$name.'[value]']->getValue());
        self::assertSame('Rauer Belag', $form[$name.'[de][valueTranslation]']->getValue());
        $form[$name.'[de][keyTranslation]'] = 'Geprüfte Oberfläche';
        $client->submit($form);
        self::assertResponseRedirects();
        $catalog = new TagTranslationCatalog($this->directory);
        self::assertArrayNotHasKey($originalKey, $catalog->labels('de')['labels']);
        self::assertSame('Geprüfte Oberfläche', $catalog->labels('de')['labels'][$this->key]);
        $valueId = substr(TagTranslationCatalog::valueId($this->key, $this->value), 6);
        self::assertSame('Rauer Belag', $catalog->labels('de')['values'][$valueId]);
        self::assertSame($english, file_get_contents($this->directory.'/tags.en.xlf'));
        // Empty English fields do not create translated labels.
        self::assertArrayNotHasKey($this->key, $catalog->labels('en')['labels']);
        $manager = static::getContainer()->get('doctrine')->getManager();
        $manager->clear();
        $node = $manager->find(Node::class, $this->nodeId);
        self::assertTrue($node->getNodeTags()->first()->isApproved());
        self::assertSame('Rauer Belag', $node->getNodeTags()->first()->getTag()->getTranslations()['de']['valueTranslation']);
    }

    public function testInvalidAndStaleAdminEditsKeepObservationPendingAndCatalogsUnchanged(): void
    {
        $client = $this->submitObservation();
        $crawler = $this->review($client);
        $form = $crawler->selectButton('Approve')->form();
        $name = $form->getName();
        $german = file_get_contents($this->directory.'/tags.de.xlf');
        $form[$name.'[de][valueTranslation]'] = "Invalid\x01";
        $client->submit($form);
        self::assertResponseStatusCodeSame(422);
        self::assertSame($german, file_get_contents($this->directory.'/tags.de.xlf'));
        $crawler = $this->review($client);
        $stale = $crawler->selectButton('Approve')->form();
        $save = $crawler->selectButton('Save changes')->form();
        $save[$name.'[value]'] = $this->value.'_edited';
        $client->submit($save);
        self::assertResponseRedirects();
        $this->value .= '_edited';
        $client->submit($stale);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'proposed translations changed');
        self::assertSame($german, file_get_contents($this->directory.'/tags.de.xlf'));
        $client->request('GET', '/admin/pending-observation/'.$this->nodeId.'/edit');
        self::assertSelectorExists('button[name$="[approve]"]');
    }

    public function testApprovalRejectsChangedCatalogAndCanClearReviewedTranslations(): void
    {
        $client = $this->submitObservation();
        $crawler = $this->review($client);
        $form = $crawler->selectButton('Approve')->form();
        $editor = static::getContainer()->get(TagTranslationCatalogEditor::class);
        $editor->merge($this->directory.'/tags.de.xlf', 'de', [
            'key:'.$this->key => ['source' => $this->key, 'note' => 'Test key', 'target' => 'Changed catalog'],
        ]);
        $client->submit($form);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'translation changed');
        self::assertSame('Changed catalog', (new TagTranslationCatalog($this->directory))->labels('de')['labels'][$this->key]);
        $crawler = $this->review($client);
        $form = $crawler->selectButton('Approve')->form();
        $name = $form->getName();
        $form[$name.'[de][keyTranslation]'] = '';
        $form[$name.'[de][valueTranslation]'] = '';
        $client->submit($form);
        self::assertResponseRedirects();
        $catalog = (new TagTranslationCatalog($this->directory))->labels('de');
        self::assertArrayNotHasKey($this->key, $catalog['labels']);
        self::assertArrayNotHasKey(substr(TagTranslationCatalog::valueId($this->key, $this->value), 6), $catalog['values']);
    }

    public function testApprovePublishesTranslationsForEditedIdentifiers(): void
    {
        $client = $this->submitObservation();
        static::getContainer()->get(TagTranslationCatalogEditor::class)->merge($this->directory.'/tags.de.xlf', 'de', [
            'key:'.$this->key => ['source' => $this->key, 'note' => 'Original key', 'target' => 'Original published label'],
        ]);
        $crawler = $this->review($client);
        $form = $crawler->selectButton('Approve')->form();
        $name = $form->getName();
        $originalKey = $this->key;
        $editedKey = $this->key.'_approved';
        $form[$name.'[key]'] = $editedKey;
        $form[$name.'[value]'] = 'edited_value';
        $client->submit($form);
        self::assertResponseRedirects();
        $this->key = $editedKey;
        $this->value = 'edited_value';
        $catalog = (new TagTranslationCatalog($this->directory))->labels('de');
        self::assertSame('Original published label', $catalog['labels'][$originalKey]);
        self::assertSame('Oberfläche & <Belag>', $catalog['labels'][$this->key]);
        self::assertSame('Rau', $catalog['values'][substr(TagTranslationCatalog::valueId($this->key, $this->value), 6)]);
        $manager = static::getContainer()->get('doctrine')->getManager();
        $manager->clear();
        $nodeTag = $manager->find(Node::class, $this->nodeId)->getNodeTags()->first();
        self::assertTrue($nodeTag->isApproved());
        self::assertSame($this->key, $nodeTag->getTag()->getKey());
        self::assertSame($this->value, $nodeTag->getTag()->getValue());
    }

    public function testReviewRejectsDuplicateAndInvalidRawFields(): void
    {
        $client = $this->submitObservation();
        $duplicate = new Tag();
        $duplicate->setKey($this->key.'_duplicate');
        $duplicate->setValue('existing');
        $manager = static::getContainer()->get('doctrine')->getManager();
        $manager->persist($duplicate);
        $manager->flush();
        $duplicateId = $duplicate->getId();
        try {
            $german = file_get_contents($this->directory.'/tags.de.xlf');
            foreach ([['key' => $duplicate->getKey(), 'value' => 'existing'], ['key' => '  '], ['value' => str_repeat('a', 256)], ['key' => "bad\x01"]] as $edits) {
                $crawler = $this->review($client);
                $form = $crawler->selectButton('Approve')->form();
                $name = $form->getName();
                foreach ($edits as $field => $value) {
                    $form[$name.'['.$field.']'] = $value;
                }
                $client->submit($form);
                self::assertResponseStatusCodeSame(422, json_encode($edits));
                self::assertSame($german, file_get_contents($this->directory.'/tags.de.xlf'));
            }
            $manager = static::getContainer()->get('doctrine')->getManager();
            $manager->clear();
            $nodeTag = $manager->find(Node::class, $this->nodeId)->getNodeTags()->first();
            self::assertFalse($nodeTag->isApproved());
            self::assertSame($this->key, $nodeTag->getTag()->getKey());
            self::assertSame($this->value, $nodeTag->getTag()->getValue());
        } finally {
            $manager = static::getContainer()->get('doctrine')->getManager();
            $manager->clear();
            $manager->remove($manager->find(Tag::class, $duplicateId));
            $manager->flush();
        }
    }

    public function testApiRejectsInvalidTranslationsAndPreservesLanguageDrafts(): void
    {
        $client = $this->submitObservation();
        $german = file_get_contents($this->directory.'/tags.de.xlf');
        $client->jsonRequest('POST', '/api/nodes', $this->payload);
        self::assertResponseIsSuccessful();
        $this->payload['translations'][0]['keyTranslation'] = 'Stale';
        $client->jsonRequest('POST', '/api/nodes', $this->payload);
        self::assertResponseStatusCodeSame(409);
        $this->payload['translationLocale'] = 'en';
        $this->payload['translations'][0]['keyTranslation'] = 'Surface';
        $client->jsonRequest('POST', '/api/nodes', $this->payload);
        self::assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('Surface', $data['observationTranslations'][$this->key]['en']['keyTranslation']);
        self::assertSame('Oberfläche & <Belag>', $data['observationTranslations'][$this->key]['de']['keyTranslation']);
        self::assertSame($german, file_get_contents($this->directory.'/tags.de.xlf'));
        $this->payload['translationLocale'] = 'fr';
        $client->jsonRequest('POST', '/api/nodes', $this->payload);
        self::assertResponseStatusCodeSame(422);
        $this->payload['translationLocale'] = 'de';
        $this->payload['translations'] = [null];
        $client->jsonRequest('POST', '/api/nodes', $this->payload);
        self::assertResponseStatusCodeSame(422);
    }
}
