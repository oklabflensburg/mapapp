<?php

namespace App\Tests\Service;

use App\Service\TagKeyLabeler;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class TagKeyLabelerTest extends KernelTestCase
{
    public function testUnknownKeyFallsBackToThePrettifiedKey(): void
    {
        $labeler = static::getContainer()->get(TagKeyLabeler::class);
        self::assertSame('some unknown key', $labeler->label('some_unknown_key'));
    }

    public function testCatalogueLabelsAreUsedPerLocale(): void
    {
        $labeler = static::getContainer()->get(TagKeyLabeler::class);
        self::assertSame('Rollstuhlzugänglichkeit', $labeler->label('wheelchair', 'de'));
        self::assertSame('Öffnungszeiten', $labeler->label('opening_hours', 'de'));
        self::assertSame('opening hours', $labeler->label('opening_hours', 'en'));
    }

    public function testValuesAreContextualAndUnknownValuesArePreserved(): void
    {
        $labeler = static::getContainer()->get(TagKeyLabeler::class);
        self::assertSame('Ja', $labeler->valueLabel('wheelchair', 'yes', 'de'));
        self::assertSame('Yes', $labeler->valueLabel('wheelchair', 'yes', 'en'));
        self::assertSame('yes', $labeler->valueLabel('name', 'yes', 'de'));
        self::assertSame('my_place & <name>', $labeler->valueLabel('name', 'my_place & <name>', 'de'));
    }
}
