<?php

namespace App\Tests\Service;

use App\Service\TagKeyLabeler;
use App\Service\TagTranslationCatalog;
use App\Service\TagTranslationCatalogEditor;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class TagTranslationCatalogEditorTest extends KernelTestCase
{
    public function testSyncPreservesTranslationsNotesAndCustomEntries(): void
    {
        $directory = sys_get_temp_dir().'/tag-catalog-'.bin2hex(random_bytes(8));
        mkdir($directory);
        $path = $directory.'/tags.de.xlf';
        copy(\dirname(__DIR__).'/Fixtures/tag-translations/tags.de.xlf', $path);
        try {
            $editor = static::getContainer()->get(TagTranslationCatalogEditor::class);
            $id = TagTranslationCatalog::valueId('custom:key', 'a_"b" & <c>');
            $entry = ['source' => 'a_"b" & <c>', 'note' => 'Context & notes', 'target' => 'Übersetzt & <gut>'];
            self::assertSame(1, $editor->merge($path, 'de', [$id => $entry]));
            $entryWithoutTarget = ['source' => 'changed source', 'note' => 'changed notes'];
            self::assertSame(1, $editor->merge($path, 'de', [
                $id => $entryWithoutTarget,
                'key:user_created' => ['source' => 'user created', 'note' => 'Custom key'],
            ]));
            $labeler = new TagKeyLabeler(new TagTranslationCatalog($directory), static::getContainer()->get('translation.locale_switcher'));
            self::assertSame('Übersetzt & <gut>', $labeler->valueLabel('custom:key', 'a_"b" & <c>', 'de'));
            $first = file_get_contents($path);
            self::assertSame(0, $editor->merge($path, 'de', [$id => $entryWithoutTarget]));
            self::assertSame($first, file_get_contents($path));
            self::assertStringContainsString('Context &amp; notes', $first);
            self::assertStringContainsString('state="final"', $first);
            $labels = (new TagTranslationCatalog($directory))->labels('de');
            self::assertSame('Übersetzt & <gut>', $labels['values'][substr($id, 6)]);
            self::assertSame('Rollstuhlzugänglichkeit', $labels['labels']['wheelchair']);
            self::assertArrayNotHasKey('user_created', $labels['labels']);
            $editor->merge($path, 'de', [$id => [...$entry, 'target' => 'Neue Übersetzung']]);
            $labeler->reset();
            self::assertSame('Neue Übersetzung', $labeler->valueLabel('custom:key', 'a_"b" & <c>', 'de'));
        } finally {
            unlink($path);
            rmdir($directory);
        }
    }
}
