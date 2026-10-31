<?php

namespace App\Tests\Command;

use App\Command\SyncTagTranslationsCommand;
use App\Entity\Tag;
use App\Service\TagTranslationCatalog;
use App\Service\TagTranslationCatalogEditor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class SyncTagTranslationsCommandTest extends KernelTestCase
{
    public function testImportsQuotedTsvAndPreservesTranslationsOnRepeatedSync(): void
    {
        $directory = sys_get_temp_dir().'/tag-sync-'.bin2hex(random_bytes(8));
        mkdir($directory);
        foreach (['en', 'de'] as $locale) {
            copy(\dirname(__DIR__).'/Fixtures/tag-translations/tags.'.$locale.'.xlf', $directory.'/tags.'.$locale.'.xlf');
        }
        $pairs = $directory.'/pairs.tsv';
        $handle = fopen($pairs, 'w');
        $raw = "Unicode 😀 & <test>\n\"quoted\"\tvalue";
        fputcsv($handle, [1, 'custom_sync_key', $raw], separator: "\t", escape: '');
        fclose($handle);
        try {
            $catalog = new TagTranslationCatalog($directory);
            $editor = static::getContainer()->get(TagTranslationCatalogEditor::class);
            $tester = new CommandTester(new SyncTagTranslationsCommand(
                static::getContainer()->get(EntityManagerInterface::class), $catalog, $editor,
            ));
            self::assertSame(0, $tester->execute(['--pairs' => $pairs]));
            self::assertStringContainsString('en: 2 new translation units', $tester->getDisplay());
            $id = TagTranslationCatalog::valueId('custom_sync_key', $raw);
            foreach (['en', 'de'] as $locale) {
                $document = new \DOMDocument();
                $document->load($catalog->path($locale));
                $units = [];
                foreach ($document->getElementsByTagNameNS('urn:oasis:names:tc:xliff:document:2.0', 'unit') as $unit) {
                    $units[$unit->getAttribute('name')] = $unit;
                }
                self::assertSame('osm', $units['key:custom_sync_key']->getAttributeNS('urn:accessapp:tag-references:1', 'category'));
                self::assertSame('osm', $units[$id]->getAttributeNS('urn:accessapp:tag-references:1', 'category'));
                self::assertSame(0, $units[$id]->getElementsByTagName('notes')->length);
                self::assertSame($units['key:custom_sync_key']->getAttribute('id'), $units[$id]->getAttributeNS('urn:accessapp:tag-references:1', 'keyRef'));
                self::assertSame($units[$id]->getAttribute('id'), $units['key:custom_sync_key']->getAttributeNS('urn:accessapp:tag-references:1', 'valueRefs'));
            }
            $editor->merge($catalog->path('de'), 'de', [$id => ['source' => $raw, 'note' => 'Context', 'target' => 'Übersetzt']]);
            $before = file_get_contents($catalog->path('de'));
            self::assertSame(0, $tester->execute(['--pairs' => $pairs]));
            self::assertSame($before, file_get_contents($catalog->path('de')));
            self::assertSame('Übersetzt', $catalog->labels('de')['values'][substr($id, 6)]);
            self::assertSame('Rollstuhlzugänglichkeit', $catalog->labels('de')['labels']['wheelchair']);
            file_put_contents($pairs, "broken row\n");
            try {
                $tester->execute(['--pairs' => $pairs]);
                self::fail('Invalid TSV must be rejected');
            } catch (\InvalidArgumentException) {
                self::assertSame($before, file_get_contents($catalog->path('de')));
            }
        } finally {
            foreach (glob($directory.'/*') as $path) {
                unlink($path);
            }
            rmdir($directory);
        }
    }
    public function testGenerationLinksSharedValuesAndRetainsExistingRelationships(): void
    {
        $directory = sys_get_temp_dir().'/tag-links-'.bin2hex(random_bytes(8));
        mkdir($directory);
        foreach (['en', 'de'] as $locale) {
            copy(\dirname(__DIR__).'/Fixtures/tag-translations/tags.'.$locale.'.xlf', $directory.'/tags.'.$locale.'.xlf');
        }
        $pairs = $directory.'/pairs.tsv';
        file_put_contents($pairs, "1\twheelchair\tyes\n1\twheelchair\tno\n1\taccess\tyes\n");
        try {
            $catalog = new TagTranslationCatalog($directory);
            $editor = static::getContainer()->get(TagTranslationCatalogEditor::class);
            // Existing standalone value units get their missing key on generation.
            $orphan = TagTranslationCatalog::valueId('orphan', 'yes');
            $editor->merge($catalog->path('de'), 'de', [$orphan => ['source' => 'yes', 'note' => 'Keep this note', 'target' => 'Ja']]);
            $tester = new CommandTester(new SyncTagTranslationsCommand(
                static::getContainer()->get(EntityManagerInterface::class), $catalog, $editor,
            ));
            self::assertSame(0, $tester->execute(['--pairs' => $pairs]));
            // A later input only includes one new pair. Old references still exist.
            file_put_contents($pairs, "1\taccess\tno\n");
            self::assertSame(0, $tester->execute(['--pairs' => $pairs]));
            foreach (['en', 'de'] as $locale) {
                $document = new \DOMDocument();
                $document->load($catalog->path($locale));
                $units = [];
                foreach ($document->getElementsByTagNameNS('urn:oasis:names:tc:xliff:document:2.0', 'unit') as $unit) {
                    $units[$unit->getAttribute('name')] = $unit;
                }
                foreach (['wheelchair', 'access'] as $key) {
                    $references = explode(' ', $units['key:'.$key]->getAttributeNS('urn:accessapp:tag-references:1', 'valueRefs'));
                    self::assertCount(2, $references);
                    foreach (['yes', 'no'] as $value) {
                        $unit = $units[TagTranslationCatalog::valueId($key, $value)];
                        self::assertContains($unit->getAttribute('id'), $references);
                        self::assertSame($units['key:'.$key]->getAttribute('id'), $unit->getAttributeNS('urn:accessapp:tag-references:1', 'keyRef'));
                    }
                }
                // Target-only admin edits preserve generated references.
                $editor->updateTarget($catalog->path($locale), $locale, 'key:wheelchair', 'Reviewed', $catalog->labels($locale)['labels']['wheelchair']);
                self::assertStringContainsString('tag:valueRefs=', file_get_contents($catalog->path($locale)));
            }
            $before = file_get_contents($catalog->path('de'));
            self::assertSame(0, $tester->execute(['--pairs' => $pairs]));
            self::assertSame($before, file_get_contents($catalog->path('de')));
            self::assertSame('Ja', $catalog->labels('de')['values'][substr($orphan, 6)]);
            self::assertStringContainsString('Keep this note', $before);
            self::assertStringContainsString('name="key:orphan"', $before);
        } finally {
            foreach (glob($directory.'/*') as $path) {
                unlink($path);
            }
            rmdir($directory);
        }
    }

    public function testGenerationClassifiesSourcesAndRemovesRedundantValueNotes(): void
    {
        $suffix = bin2hex(random_bytes(8));
        $directory = sys_get_temp_dir().'/tag-categories-'.$suffix;
        mkdir($directory);
        foreach (['en', 'de'] as $locale) {
            copy(\dirname(__DIR__).'/Fixtures/tag-translations/tags.'.$locale.'.xlf', $directory.'/tags.'.$locale.'.xlf');
        }
        $sharedKey = 'shared_'.$suffix;
        $customKey = 'custom_'.$suffix;
        $osmKey = 'osm_'.$suffix;
        $keys = $directory.'/keys.tsv';
        $pairs = $directory.'/pairs.tsv';
        file_put_contents($keys, "1\t".$osmKey."\n");
        file_put_contents($pairs, "1\t".$sharedKey."\tyes\n");
        $manager = static::getContainer()->get(EntityManagerInterface::class);
        $tags = [];
        foreach ([[$sharedKey, 'yes'], [$sharedKey, 'custom-choice'], [$customKey, 'free text']] as [$key, $value]) {
            $tag = new Tag();
            $tag->setKey($key);
            $tag->setValue($value);
            $manager->persist($tag);
            $tags[] = $tag;
        }
        $manager->flush();
        try {
            $catalog = new TagTranslationCatalog($directory);
            $editor = static::getContainer()->get(TagTranslationCatalogEditor::class);
            $sharedValue = TagTranslationCatalog::valueId($sharedKey, 'yes');
            $editor->merge($catalog->path('de'), 'de', [
                $sharedValue => ['source' => 'yes', 'note' => 'Tag value: '.$sharedKey.'=yes', 'target' => 'Gemeinsam'],
            ]);
            $tester = new CommandTester(new SyncTagTranslationsCommand($manager, $catalog, $editor));
            // A subsequent OSM input upgrades units initially collected from the database.
            self::assertSame(0, $tester->execute(['--database' => true]));
            self::assertSame(0, $tester->execute(['keys' => $keys, '--pairs' => $pairs, '--database' => true]));
            $expected = [
                'key:'.$osmKey => 'osm', 'key:'.$sharedKey => 'osm', $sharedValue => 'osm',
                TagTranslationCatalog::valueId($sharedKey, 'custom-choice') => 'custom',
                'key:'.$customKey => 'custom', TagTranslationCatalog::valueId($customKey, 'free text') => 'custom',
            ];
            foreach (['en', 'de'] as $locale) {
                $document = new \DOMDocument();
                $document->load($catalog->path($locale));
                foreach ($document->getElementsByTagNameNS('urn:oasis:names:tc:xliff:document:2.0', 'unit') as $unit) {
                    $name = $unit->getAttribute('name');
                    if (isset($expected[$name])) {
                        self::assertSame($expected[$name], $unit->getAttributeNS('urn:accessapp:tag-references:1', 'category'), $name);
                    }
                }
                self::assertStringNotContainsString('Tag value:', file_get_contents($catalog->path($locale)));
                self::assertStringContainsString('tag:keyRef=', file_get_contents($catalog->path($locale)));
            }
            self::assertSame('Gemeinsam', $catalog->labels('de')['values'][substr($sharedValue, 6)]);
            $editor->merge($catalog->path('de'), 'de', [$sharedValue => ['source' => '', 'note' => '', 'ignore' => true]]);
            $before = file_get_contents($catalog->path('de'));
            // Partial database syncs preserve established OSM provenance.
            self::assertSame(0, $tester->execute(['--database' => true]));
            self::assertSame($before, file_get_contents($catalog->path('de')));
            $editor->updateTarget($catalog->path('de'), 'de', $sharedValue, 'Reviewed', 'Gemeinsam');
            self::assertStringContainsString('tag:category="osm"', file_get_contents($catalog->path('de')));
            self::assertSame(0, $tester->execute(['keys' => $keys, '--pairs' => $pairs, '--database' => true]));
            self::assertSame('Reviewed', $catalog->labels('de')['values'][substr($sharedValue, 6)]);
            $units = array_column(iterator_to_array($catalog->entries('de')), null, 'unit');
            self::assertTrue($units[$sharedValue]['ignore']);
            self::assertFalse($units['key:'.$sharedKey]['ignore']);
        } finally {
            foreach ($tags as $tag) {
                $manager->remove($tag);
            }
            $manager->flush();
            foreach (glob($directory.'/*') as $path) {
                unlink($path);
            }
            rmdir($directory);
        }
    }

}
