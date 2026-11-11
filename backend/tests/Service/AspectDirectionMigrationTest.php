<?php

namespace App\Tests\Service;

use App\Entity\NodeGroup;
use App\Entity\NodeGroupTag;
use App\Entity\Tag;
use App\Service\AspectDirectionMigration;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The direction migration rewrites legacy direction-less structured tags
 * into the `way.<type>.<direction>.<index>.<aspect>` grammar, driven by an
 * explicit position mapping (see
 * specs/map-editing/street-part-aspects/spec.md R8).
 */
final class AspectDirectionMigrationTest extends KernelTestCase
{
    use \App\Tests\Controller\CreatesUsers;

    private EntityManagerInterface $manager;
    /** @var list<int> */
    private array $groupIds = [];
    /** @var list<int> */
    private array $tagIds = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->manager = static::getContainer()->get('doctrine')->getManager();
    }

    protected function tearDown(): void
    {
        if (!$this->manager->isOpen()) {
            $this->manager = static::getContainer()->get('doctrine')->resetManager();
        }
        $tagIds = $this->tagIds;
        foreach ($this->groupIds as $id) {
            $group = $this->manager->find(NodeGroup::class, $id);
            if ($group === null) {
                continue;
            }
            foreach ($group->getGroupTags() as $entry) {
                $tagIds[] = $entry->getTag()->getId();
            }
            $this->manager->remove($group);
        }
        foreach (array_unique($tagIds) as $id) {
            $tag = $this->manager->find(Tag::class, $id);
            if ($tag !== null) {
                $this->manager->remove($tag);
            }
        }
        $this->manager->flush();
        parent::tearDown();
    }

    private function createPart(string $identity): NodeGroup
    {
        $group = new NodeGroup();
        $group->setName('direction-migration-'.$identity);
        $group->setCreatedByUser($this->createUser('direction-migration-'.$identity.'@example.test', ['admin']));
        $this->manager->persist($group);
        $this->manager->flush();
        $this->groupIds[] = $group->getId();

        return $group;
    }

    private function addTag(NodeGroup $group, string $key, string $value, bool $approved, array $translations = []): void
    {
        $tag = $this->manager->getRepository(Tag::class)->findOneBy(['key' => $key, 'value' => $value]);
        if ($tag === null) {
            $tag = new Tag();
            $tag->setKey($key);
            $tag->setValue($value);
            $tag->setTranslations($translations);
            $this->manager->persist($tag);
            $this->manager->flush();
        }
        $this->tagIds[] = $tag->getId();
        $groupTag = new NodeGroupTag($group, $tag, $group->getCreatedByUser());
        $groupTag->setApproved($approved);
        $group->getGroupTags()->add($groupTag);
        $this->manager->persist($groupTag);
        $this->manager->flush();
    }

    /** @return list<string> key=value pairs of the group's tags */
    private function summaries(NodeGroup $group): array
    {
        $summaries = [];
        foreach ($group->getGroupTags() as $entry) {
            $summaries[] = $entry->getTag()->getKey().'='.$entry->getTag()->getValue().'/'.($entry->isApproved() ? 'approved' : 'pending');
        }
        sort($summaries);

        return $summaries;
    }

    public function testConvertsPositionsPreservesStateAndIsRerunnable(): void
    {
        $identity = bin2hex(random_bytes(4));
        $group = $this->createPart($identity);
        $this->addTag($group, 'way.footwalk.1.width', '<1-'.$identity, true, ['de' => ['keyTranslation' => 'Breite']]);
        $this->addTag($group, 'way.footwalk.1.surface', 'asphalt-'.$identity, true);
        $this->addTag($group, 'way.roadway.2.lanes', '2-'.$identity, false);
        // An already-migrated tag must stay untouched.
        $this->addTag($group, 'way.footwalk.east.1.width', '>2-'.$identity, true);

        $migration = static::getContainer()->get(AspectDirectionMigration::class);
        $migration->convert(['footwalk.1' => 'west', 'roadway.2' => 'center']);
        $migration->convert(['footwalk.1' => 'west', 'roadway.2' => 'center']); // rerun is a no-op

        $this->manager->clear();
        $reloaded = $this->manager->find(NodeGroup::class, $group->getId());
        self::assertSame([
            'way.footwalk.east.1.width=>2-'.$identity.'/approved',
            'way.footwalk.west.1.surface=asphalt-'.$identity.'/approved',
            'way.footwalk.west.1.width=<1-'.$identity.'/approved',
            'way.roadway.center.2.lanes=2-'.$identity.'/pending',
        ], $this->summaries($reloaded));
        // Translation proposals travel to the rewritten key.
        $width = $this->manager->getRepository(Tag::class)->findOneBy(['key' => 'way.footwalk.west.1.width', 'value' => '<1-'.$identity]);
        self::assertSame(['de' => ['keyTranslation' => 'Breite']], $width->getTranslations());
    }

    public function testUnmappedPositionAbortsBeforeWriting(): void
    {
        $identity = bin2hex(random_bytes(4));
        $group = $this->createPart($identity);
        $this->addTag($group, 'way.footwalk.3.width', '<1-'.$identity, true);

        $migration = static::getContainer()->get(AspectDirectionMigration::class);
        try {
            $migration->convert(['roadway.3' => 'west']);
            self::fail('Unmapped legacy positions must abort the migration.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('footwalk.3', $exception->getMessage());
        }
        $this->manager->clear();
        $reloaded = $this->manager->find(NodeGroup::class, $group->getId());
        self::assertSame(['way.footwalk.3.width=<1-'.$identity.'/approved'], $this->summaries($reloaded));
    }

    public function testValueCollisionAbortsWithActionableReport(): void
    {
        $identity = bin2hex(random_bytes(4));
        $group = $this->createPart($identity);
        $this->addTag($group, 'way.footwalk.1.width', '<1-'.$identity, true);
        $this->addTag($group, 'way.footwalk.west.1.width', '>2-'.$identity, true);

        $migration = static::getContainer()->get(AspectDirectionMigration::class);
        try {
            $migration->convert(['footwalk.1' => 'west']);
            self::fail('Differing existing values must abort the migration.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('collision', $exception->getMessage());
        }
        $this->manager->clear();
        $reloaded = $this->manager->find(NodeGroup::class, $group->getId());
        self::assertCount(2, $reloaded->getGroupTags());
    }
}
