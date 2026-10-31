<?php

namespace App\Tests\Controller;

use App\Service\TagTranslationCatalog;
use App\Service\TagTranslationCatalogEditor;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Form;

final class TagTranslationControllerTest extends WebTestCase
{
    use CreatesUsers;

    private ?string $directory = null;

    protected function tearDown(): void
    {
        parent::tearDown();
        if ($this->directory !== null) {
            foreach (glob($this->directory.'/*') as $path) {
                unlink($path);
            }
            rmdir($this->directory);
        }
    }

    private function adminClient(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->directory = sys_get_temp_dir().'/admin-translations-'.bin2hex(random_bytes(8));
        mkdir($this->directory);
        foreach (['en', 'de'] as $locale) {
            copy(\dirname(__DIR__).'/Fixtures/tag-translations/tags.'.$locale.'.xlf', $this->directory.'/tags.'.$locale.'.xlf');
        }
        static::getContainer()->set(TagTranslationCatalog::class, new TagTranslationCatalog($this->directory));
        $editor = static::getContainer()->get(TagTranslationCatalogEditor::class);
        foreach (['de', 'en'] as $locale) {
            $editor->merge($this->directory.'/tags.'.$locale.'.xlf', $locale, [
                TagTranslationCatalog::valueId('wheelchair', 'yes') => ['source' => 'Yes', 'note' => 'Tag value: wheelchair=yes', 'target' => $locale === 'de' ? 'Ja' : 'Yes'],
            ], linkReferences: true);
        }
        $client->loginUser($this->createUser('translation-editor-admin@example.test', ['admin']), 'admin');

        return $client;
    }

    private function openForm(KernelBrowser $client, string $query = ''): Form
    {
        $client->request('GET', '/admin/translations'.$query);
        $crawler = $client->followRedirect();
        self::assertResponseIsSuccessful();

        return $crawler->selectButton('Save all changes')->form();
    }

    private function field(Form $form, string $unit, string $locale, string $field = 'target'): string
    {
        foreach ($form->getPhpValues()['translations']['entries'] as $index => $entry) {
            if ($entry['unit'] === $unit) {
                return 'translations[entries]['.$index.']['.$locale.']['.$field.']';
            }
        }
        throw new \LogicException('Unit not found: '.$unit);
    }

    private function ignoreField(Form $form, string $unit): string
    {
        foreach ($form->getPhpValues()['translations']['entries'] as $index => $entry) {
            if ($entry['unit'] === $unit) {
                return 'translations[entries]['.$index.'][ignore]';
            }
        }
        throw new \LogicException('Unit not found: '.$unit);
    }

    private function files(): array
    {
        return ['de' => file_get_contents($this->directory.'/tags.de.xlf'), 'en' => file_get_contents($this->directory.'/tags.en.xlf')];
    }

    public function testBilingualKeyValueLayoutAndSingleSavePublishesAllEdits(): void
    {
        $client = $this->adminClient();
        $form = $this->openForm($client);
        self::assertSelectorTextContains('thead', 'Translation German');
        self::assertSelectorTextContains('thead', 'Translation English');
        self::assertCount(1, $client->getCrawler()->selectButton('Save all changes'));
        self::assertSelectorNotExists('select[name="locale"]');
        $rows = $client->getCrawler()->filter('tbody tr');
        self::assertSame('key:wheelchair', $rows->eq(0)->attr('data-translation-unit'));
        $value = TagTranslationCatalog::valueId('wheelchair', 'yes');
        self::assertSame($value, $rows->eq(1)->attr('data-translation-unit'));
        $before = $this->files();
        $client->submit($form);
        self::assertResponseStatusCodeSame(303);
        self::assertSame($before, $this->files(), 'Unchanged fields must not rewrite either catalog');
        $form = $this->openForm($client);
        $form[$this->field($form, 'key:wheelchair', 'de')] = 'Rollstuhl & <Zugang> 😀';
        $form[$this->field($form, 'key:wheelchair', 'en')] = 'Wheelchair access';
        $form[$this->field($form, $value, 'de')] = 'Ja, zugänglich';
        $form[$this->field($form, $value, 'en')] = 'Accessible';
        $client->submit($form);
        self::assertResponseStatusCodeSame(303);
        $catalog = new TagTranslationCatalog($this->directory);
        self::assertSame('Rollstuhl & <Zugang> 😀', $catalog->labels('de')['labels']['wheelchair']);
        self::assertSame('Wheelchair access', $catalog->labels('en')['labels']['wheelchair']);
        self::assertSame('Ja, zugänglich', $catalog->labels('de')['values']['["wheelchair","yes"]']);
        self::assertSame('Accessible', $catalog->labels('en')['values']['["wheelchair","yes"]']);
        foreach ($this->files() as $xml) {
            self::assertStringContainsString('tag:keyRef=', $xml);
            self::assertStringContainsString('tag:valueRefs=', $xml);
            self::assertStringContainsString('<source>wheelchair</source>', $xml);
        }
        $client->request('GET', '/api/i18n/tag-keys/de');
        self::assertSame('Ja, zugänglich', json_decode($client->getResponse()->getContent(), true)['values']['["wheelchair","yes"]']);
        $form = $this->openForm($client);
        $form[$this->field($form, $value, 'de')] = '';
        $form[$this->field($form, 'key:wheelchair', 'en')] = '';
        $client->submit($form);
        self::assertResponseStatusCodeSame(303);
        self::assertArrayNotHasKey('["wheelchair","yes"]', $catalog->labels('de')['values']);
        self::assertArrayNotHasKey('wheelchair', $catalog->labels('en')['labels']);
        self::assertSame('Accessible', $catalog->labels('en')['values']['["wheelchair","yes"]']);
    }

    public function testStaleEnglishEditPreventsGermanAndEnglishWritesAndPreservesDrafts(): void
    {
        $client = $this->adminClient();
        $form = $this->openForm($client);
        $form[$this->field($form, 'key:wheelchair', 'de')] = 'German draft';
        $englishField = $this->field($form, 'key:wheelchair', 'en');
        $form[$englishField] = 'English draft';
        static::getContainer()->get(TagTranslationCatalogEditor::class)->updateTarget($this->directory.'/tags.en.xlf', 'en', 'key:wheelchair', 'Newer edit', 'wheelchair');
        $before = $this->files();
        $client->submit($form);
        self::assertResponseStatusCodeSame(422);
        self::assertSame($before, $this->files());
        self::assertSelectorTextContains('.translation-draft', 'changed since you opened');
        $retry = $client->getCrawler()->selectButton('Save all changes')->form();
        self::assertSame('English draft', $retry[$englishField]->getValue());
        self::assertSame('German draft', $retry[$this->field($retry, 'key:wheelchair', 'de')]->getValue());
        self::assertSame([], glob($this->directory.'/.tag*'));
    }

    public function testInvalidCsrfXmlAndUnknownUnitsDoNotChangeEitherFile(): void
    {
        $client = $this->adminClient();
        foreach (['csrf', 'xml', 'unit'] as $case) {
            $form = $this->openForm($client);
            $before = $this->files();
            $form[$this->field($form, 'key:wheelchair', 'de')] = '<script>draft</script>';
            if ($case === 'csrf') {
                $form['translations[_token]'] = 'invalid';
            } elseif ($case === 'xml') {
                $form[$this->field($form, 'key:wheelchair', 'en')] = "Invalid\x01XML";
            } else {
                $form['translations[entries][0][unit]'] = 'key:missing';
            }
            $client->submit($form);
            self::assertResponseStatusCodeSame(422);
            self::assertSame($before, $this->files());
            self::assertSelectorNotExists('script:not([src]):contains("draft")');
            self::assertStringContainsString('&lt;script&gt;draft&lt;/script&gt;', $client->getResponse()->getContent());
        }
    }

    public function testSearchBothLanguagesUntranslatedFilterAndPagination(): void
    {
        $client = $this->adminClient();
        $entries = [];
        for ($index = 0; $index < 30; ++$index) {
            $entries['key:custom_'.$index] = ['source' => 'Custom '.$index, 'note' => 'Custom key'];
        }
        foreach (['de', 'en'] as $locale) {
            static::getContainer()->get(TagTranslationCatalogEditor::class)->merge($this->directory.'/tags.'.$locale.'.xlf', $locale, $entries);
        }
        $this->openForm($client, '?q=custom&untranslated=1');
        self::assertCount(25, $client->getCrawler()->filter('tbody tr'));
        self::assertSelectorTextContains('.translation-summary', '30 matching tags');
        $client->clickLink('Next');
        self::assertResponseIsSuccessful();
        self::assertCount(5, $client->getCrawler()->filter('tbody tr'));
        self::assertSelectorTextContains('.translation-pagination', 'Page 2 of 2');
        $form = $this->openForm($client, '?q=wheelchair');
        $form[$this->field($form, 'key:wheelchair', 'en')] = 'English searchable label';
        $client->submit($form);
        self::assertResponseStatusCodeSame(303);
        $this->openForm($client, '?q=searchable');
        self::assertCount(2, $client->getCrawler()->filter('tbody tr'));
        $this->openForm($client, '?q=Rollstuhl');
        self::assertCount(2, $client->getCrawler()->filter('tbody tr'));
        $client->request('GET', '/admin/translations?locale=fr');
        self::assertResponseStatusCodeSame(400);
    }

    public function testMissingLanguageCounterpartsCanBeTranslatedTogether(): void
    {
        $client = $this->adminClient();
        $value = TagTranslationCatalog::valueId('house', 'marktwache');
        static::getContainer()->get(TagTranslationCatalogEditor::class)->merge($this->directory.'/tags.de.xlf', 'de', [
            'key:house' => ['source' => 'house', 'note' => 'Tag key: house', 'target' => 'Haus'],
            $value => ['source' => 'marktwache', 'note' => 'Tag value: house=marktwache', 'target' => 'Marktwache'],
        ], linkReferences: true);
        $form = $this->openForm($client, '?q=house');
        self::assertCount(2, $client->getCrawler()->filter('tbody tr'));
        $form[$this->field($form, 'key:house', 'en')] = 'House';
        $form[$this->field($form, $value, 'en')] = 'Market guardhouse';
        $client->submit($form);
        self::assertResponseStatusCodeSame(303);
        $catalog = new TagTranslationCatalog($this->directory);
        self::assertSame('House', $catalog->labels('en')['labels']['house']);
        self::assertSame('Market guardhouse', $catalog->labels('en')['values']['["house","marktwache"]']);
        self::assertSame('Haus', $catalog->labels('de')['labels']['house']);
    }

    public function testIgnoreCheckboxSavesBothLanguagesAndFiltersByCategoryAndIgnore(): void
    {
        $client = $this->adminClient();
        $value = TagTranslationCatalog::valueId('wheelchair', 'yes');
        $editor = static::getContainer()->get(TagTranslationCatalogEditor::class);
        foreach (['de', 'en'] as $locale) {
            $editor->merge($this->directory.'/tags.'.$locale.'.xlf', $locale, [
                'key:wheelchair' => ['source' => '', 'note' => '', 'category' => 'osm'],
                $value => ['source' => '', 'note' => '', 'category' => 'custom'],
            ]);
        }
        $form = $this->openForm($client, '?category=custom&ignore=not_ignored');
        self::assertSelectorTextContains('.translation-category', 'OSM · context');
        self::assertCount(2, $client->getCrawler()->filter('tbody tr'));
        $form[$this->ignoreField($form, $value)]->tick();
        $form[$this->field($form, $value, 'de')] = 'Ja, zugänglich';
        $client->submit($form);
        self::assertResponseStatusCodeSame(303);
        self::assertStringContainsString('category=custom', $client->getResponse()->headers->get('Location'));
        self::assertStringContainsString('ignore=not_ignored', $client->getResponse()->headers->get('Location'));
        $catalog = new TagTranslationCatalog($this->directory);
        foreach (['de', 'en'] as $locale) {
            $units = array_column(iterator_to_array($catalog->entries($locale)), null, 'unit');
            self::assertTrue($units[$value]['ignore']);
            self::assertFalse($units['key:wheelchair']['ignore']);
            self::assertSame('custom', $units[$value]['category']);
            self::assertStringContainsString('tag:ignore="true"', $this->files()[$locale]);
        }
        // Ignore is a review flag: published labels still resolve normally.
        self::assertSame('Ja, zugänglich', $catalog->labels('de')['values']['["wheelchair","yes"]']);
        $this->openForm($client, '?category=osm&ignore=not_ignored');
        self::assertCount(1, $client->getCrawler()->filter('tbody tr'));
        self::assertSelectorExists('tr.translation-key-row');
        $form = $this->openForm($client, '?category=custom&ignore=ignored');
        self::assertNotNull($form[$this->ignoreField($form, $value)]->getValue());
        $form[$this->ignoreField($form, $value)]->untick();
        $client->submit($form);
        self::assertResponseStatusCodeSame(303);
        $client->followRedirect();
        self::assertSelectorTextContains('.translation-summary', '0 matching tags');
        foreach (['de', 'en'] as $locale) {
            $units = array_column(iterator_to_array($catalog->entries($locale)), null, 'unit');
            self::assertFalse($units[$value]['ignore']);
        }
        foreach (['category=invalid', 'ignore=invalid'] as $query) {
            $client->request('GET', '/admin/translations?'.$query);
            self::assertResponseStatusCodeSame(400);
        }
    }

    public function testDefaultNotIgnoredFilterHidesValuesOfIgnoredKey(): void
    {
        $client = $this->adminClient();
        $form = $this->openForm($client);
        self::assertSelectorExists('select[name="ignore"] option[value="not_ignored"][selected]');
        $form[$this->ignoreField($form, 'key:wheelchair')]->tick();
        $client->submit($form);
        self::assertResponseStatusCodeSame(303);
        $client->followRedirect();
        self::assertSelectorTextContains('.translation-summary', '0 matching tags');
        self::assertCount(0, $client->getCrawler()->filter('tbody tr'));
        $client->request('GET', '/admin/translations?q=yes');
        $client->followRedirect();
        self::assertSelectorTextContains('.translation-summary', '0 matching tags');
        $form = $this->openForm($client, '?ignore=all');
        self::assertCount(2, $client->getCrawler()->filter('tbody tr'));
        $value = TagTranslationCatalog::valueId('wheelchair', 'yes');
        self::assertNull($form[$this->ignoreField($form, $value)]->getValue());
        self::assertSelectorTextContains('tr.translation-value-row', 'Ignored via key');
        $form = $this->openForm($client, '?ignore=ignored');
        self::assertCount(2, $client->getCrawler()->filter('tbody tr'));
        $form[$this->ignoreField($form, 'key:wheelchair')]->untick();
        $client->submit($form);
        self::assertResponseStatusCodeSame(303);
        $this->openForm($client);
        self::assertCount(2, $client->getCrawler()->filter('tbody tr'));
        $catalog = new TagTranslationCatalog($this->directory);
        foreach (['de', 'en'] as $locale) {
            $units = array_column(iterator_to_array($catalog->entries($locale)), null, 'unit');
            self::assertFalse($units[$value]['ignore'], 'Hiding via a key must not overwrite the value flag');
        }
    }

    public function testStaleIgnoreChangeRejectsEntireSaveAndKeepsCheckboxDraft(): void
    {
        $client = $this->adminClient();
        $stale = $this->openForm($client);
        $form = $this->openForm($client);
        $form[$this->ignoreField($form, 'key:wheelchair')]->tick();
        $client->submit($form);
        self::assertResponseStatusCodeSame(303);
        $before = $this->files();
        $stale[$this->ignoreField($stale, 'key:wheelchair')]->tick();
        $value = TagTranslationCatalog::valueId('wheelchair', 'yes');
        $stale[$this->field($stale, $value, 'en')] = 'Unsaved English draft';
        $client->submit($stale);
        self::assertResponseStatusCodeSame(422);
        self::assertSame($before, $this->files());
        self::assertSelectorTextContains('.translation-draft', 'ignore flag changed');
        $retry = $client->getCrawler()->selectButton('Save all changes')->form();
        self::assertNotNull($retry[$this->ignoreField($retry, 'key:wheelchair')]->getValue());
        self::assertSame('Unsaved English draft', $retry[$this->field($retry, $value, 'en')]->getValue());
    }

    public function testMetadataFiltersPersistAcrossPagination(): void
    {
        $client = $this->adminClient();
        $entries = [];
        for ($index = 0; $index < 30; ++$index) {
            $entries['key:filter_'.$index] = ['source' => 'Filter '.$index, 'note' => '', 'category' => 'custom', 'ignore' => $index < 27];
        }
        foreach (['de', 'en'] as $locale) {
            static::getContainer()->get(TagTranslationCatalogEditor::class)->merge($this->directory.'/tags.'.$locale.'.xlf', $locale, $entries);
        }
        $this->openForm($client, '?q=filter_&category=custom&ignore=ignored');
        self::assertSelectorTextContains('.translation-summary', '27 matching tags');
        self::assertCount(25, $client->getCrawler()->filter('tbody tr'));
        $client->clickLink('Next');
        self::assertResponseIsSuccessful();
        self::assertCount(2, $client->getCrawler()->filter('tbody tr'));
        self::assertSelectorExists('select[name="category"] option[value="custom"][selected]');
        self::assertSelectorExists('select[name="ignore"] option[value="ignored"][selected]');
    }

    public function testAnonymousVisitorsAndEditorsCannotOpenTheEditor(): void
    {
        $client = static::createClient();
        $client->request('GET', '/admin/translations');
        self::assertResponseRedirects('/admin/login');
        $client->loginUser($this->createUser('translation-editor-user@example.test', ['editor']), 'admin');
        $client->request('GET', '/admin/translations');
        self::assertResponseStatusCodeSame(403);
        $client->request('POST', '/admin/translations', ['translations' => ['entries' => []]]);
        self::assertResponseStatusCodeSame(403);
    }
}
