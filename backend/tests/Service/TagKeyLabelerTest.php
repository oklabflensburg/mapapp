<?php

namespace App\Tests\Service;

use App\Entity\TagKeyLabel;
use App\Service\TagKeyLabeler;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class TagKeyLabelerTest extends KernelTestCase
{
    protected function tearDown(): void
    {
        $manager = static::getContainer()->get('doctrine')->getManager();
        $repository = $manager->getRepository(TagKeyLabel::class);
        foreach ($repository->findBy(['key' => 'labeler_test_key']) as $label) {
            $manager->remove($label);
        }
        $manager->flush();

        parent::tearDown();
    }

    public function testUnknownKeyFallsBackToThePrettifiedKey(): void
    {
        $labeler = static::getContainer()->get(TagKeyLabeler::class);

        self::assertSame('some unknown key', $labeler->label('some_unknown_key'));
        self::assertSame('addr:street', $labeler->label('addr:street'));
    }

    public function testCatalogueLabelsAreUsedPerLocale(): void
    {
        $labeler = static::getContainer()->get(TagKeyLabeler::class);

        self::assertSame('Rollstuhlzugänglichkeit', $labeler->label('wheelchair', 'de'));
        self::assertSame('Öffnungszeiten', $labeler->label('opening_hours', 'de'));
        // English only prettifies the common keys.
        self::assertSame('opening hours', $labeler->label('opening_hours', 'en'));
    }

    public function testAdminManagedLabelBeatsTheCatalogue(): void
    {
        $manager = static::getContainer()->get('doctrine')->getManager();
        $label = new TagKeyLabel();
        $label->setKey('labeler_test_key');
        $label->setLocale('de');
        $label->setLabel('Testetikett');
        $manager->persist($label);
        $manager->flush();

        $labeler = static::getContainer()->get(TagKeyLabeler::class);

        self::assertSame('Testetikett', $labeler->label('labeler_test_key', 'de'));
        self::assertSame('labeler test key', $labeler->label('labeler_test_key', 'en'));
    }
}
