<?php

namespace App\Tests\Controller;

use App\Entity\NodeGroup;
use App\Entity\NodeGroupTag;
use App\Entity\Tag;
use App\Entity\User;
use App\Service\TagTranslationCatalog;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The admin update-vs-add decision for pending structured street-part
 * observations: approving them as submitted adds a new element, approving
 * them with a mapping target publishes them under an existing element's
 * identity and replaces that aspect's approved value. See
 * specs/map-editing/aspect-observation-mapping/spec.md.
 */
final class AspectObservationMappingTest extends WebTestCase
{
    use CreatesUsers;

    /** @var list<int> */
    private array $groupIds = [];

    /** @var list<int> */
    private array $tagIds = [];

    protected function tearDown(): void
    {
        $manager = static::getContainer()->get('doctrine')->getManager();
        if (!$manager->isOpen()) {
            $manager = static::getContainer()->get('doctrine')->resetManager();
        }
        // Approved mappings create tags beyond the fixture list, so sweep
        // every tag referenced by the test groups as well.
        $tagIds = $this->tagIds;
        foreach ($this->groupIds as $id) {
            $group = $manager->find(NodeGroup::class, $id);
            if ($group === null) {
                continue;
            }
            foreach ($group->getGroupTags() as $entry) {
                $tagIds[] = $entry->getTag()->getId();
            }
            $manager->remove($group);
        }
        foreach (array_unique($tagIds) as $id) {
            $tag = $manager->find(Tag::class, $id);
            if ($tag !== null) {
                $manager->remove($tag);
            }
        }
        $manager->flush();
        parent::tearDown();
    }

    private function findOrCreateTag(string $key, string $value): Tag
    {
        $manager = static::getContainer()->get('doctrine')->getManager();
        $tag = $manager->getRepository(Tag::class)->findOneBy(['key' => $key, 'value' => $value]);
        if ($tag === null) {
            $tag = new Tag();
            $tag->setKey($key);
            $tag->setValue($value);
            $manager->persist($tag);
        }

        return $tag;
    }

    private function addRelationTag(NodeGroup $group, User $user, string $key, string $value, bool $approved): NodeGroupTag
    {
        $manager = static::getContainer()->get('doctrine')->getManager();
        $tag = $this->findOrCreateTag($key, $value);

        $groupTag = new NodeGroupTag($group, $tag, $user);
        $groupTag->setApproved($approved);
        // Attached directly: the admin request reuses the same managed
        // group, whose in-memory collection would otherwise not know about
        // the new row.
        $group->getGroupTags()->add($groupTag);
        $manager->persist($groupTag);
        $manager->flush();
        $this->tagIds[] = $tag->getId();

        return $groupTag;
    }

    /**
     * A street part with two approved footwalks: footwalk west 1 has width and
     * surface approved, footwalk west 2 only width. A roadway element exists to
     * prove cross-type targets are never offered.
     */
    private function createStreetPart(array $pending, string $identity): NodeGroup
    {
        $manager = static::getContainer()->get('doctrine')->getManager();
        $editor = $this->createUser('mapping-editor-'.$identity.'@example.test', ['editor']);
        $admin = $this->createUser('mapping-approver-'.$identity.'@example.test', ['admin']);

        $group = new NodeGroup();
        $group->setName('mapping-part-'.$identity);
        $group->setCreatedByUser($admin);
        $manager->persist($group);

        // Approved fixtures first: their insertions supersede same-key
        // pending tags through the doctrine approval listener, so pending
        // rows are added only after these are flushed.
        $this->addPending($group, $admin, 'way.footwalk.west.1.width', '<1-'.$identity, true);
        $this->addPending($group, $admin, 'way.footwalk.west.1.surface', 'asphalt-'.$identity, true);
        $this->addPending($group, $admin, 'way.footwalk.west.2.width', '1-2-'.$identity, true);
        $this->addPending($group, $admin, 'way.roadway.west.4.lanes', '2-'.$identity, true);

        $manager->flush();
        $this->groupIds[] = $group->getId();

        foreach ($pending as [$key, $value]) {
            $this->addPending($group, $editor, $key, $value, false);
        }

        return $group;
    }

    private function addPending(NodeGroup $group, User $user, string $key, string $value, bool $approved): void
    {
        $this->addRelationTag($group, $user, $key, $value, $approved);
    }

    private function loginUser(KernelBrowser $client): User
    {
        $admin = $this->createUser('mapping-review-admin-'.bin2hex(random_bytes(4)).'@example.test', ['admin']);
        $client->loginUser($admin, 'admin');

        return $admin;
    }

    private function reviewPage(KernelBrowser $client, NodeGroup $group): Crawler
    {
        return $client->request('GET', '/admin/pending-relation-observation/'.$group->getId().'/edit');
    }

    /**
     * Extracts a mapping option value from the row's publish-target select.
     */
    private function targetOption(NodeGroupTag $entry, int $position): string
    {
        $client = static::getClient();
        $crawler = $this->reviewPage($client, $entry->getGroup());
        self::assertResponseIsSuccessful();

        return $crawler
            ->filter(sprintf('form[action="/admin/node-group-tag/%d/approve"]', $entry->getId()))
            ->filter('select[name="target_index"] option')
            ->eq($position)
            ->attr('value');
    }

    /**
     * Submits the row's approve action with a mapping payload selected.
     */
    private function approveWithTarget(KernelBrowser $client, NodeGroupTag $entry, string $payload): void
    {
        $crawler = $this->reviewPage($client, $entry->getGroup());
        self::assertResponseIsSuccessful();
        $button = $crawler
            ->filter(sprintf('form[action="/admin/node-group-tag/%d/approve"]', $entry->getId()))
            ->filter('button[name$="[approve]"]');
        $form = $button->form();
        $client->submit($form, ['target_index' => $payload]);
    }

    /**
     * @return list<string> key=value pairs of the group's tags
     */
    private function groupTagSummaries(NodeGroup $group): array
    {
        $summaries = [];
        foreach ($group->getGroupTags() as $groupTag) {
            $summaries[] = $groupTag->getTag()->getKey().'='.$groupTag->getTag()->getValue();
        }
        sort($summaries);

        return $summaries;
    }

    private function reloadGroup(NodeGroup $group): NodeGroup
    {
        $manager = static::getContainer()->get('doctrine')->getManager();
        $manager->clear();

        return $manager->find(NodeGroup::class, $group->getId());
    }

    public function testReviewShowsApprovedCrossSectionAndSameTypeTargetsOnly(): void
    {
        $identity = bin2hex(random_bytes(4));
        $client = static::createClient();
        $this->loginUser($client);

        $group = $this->createStreetPart([['way.footwalk.west.3.width', '>2-'.$identity]], $identity);
        $crawler = $this->reviewPage($client, $group);

        // Approved cross-section context (AC1).
        self::assertSelectorTextContains('.approved-cross-section', 'footwalk west 1');
        self::assertSelectorTextContains('.approved-cross-section', 'width=<1-'.$identity);
        self::assertSelectorTextContains('.approved-cross-section', 'roadway west 4');

        // Mapping options list existing approved footwalks only (AC6): the
        // roadway element is never offered, and the pending element itself
        // is not a target.
        $options = $crawler->filter('select[name="target_index"] option');
        self::assertSame(3, $options->count()); // default + footwalk west 1 + footwalk west 2
        self::assertStringContainsString('footwalk west 1 (width=<1-'.$identity.', surface=asphalt-'.$identity.')', $options->eq(1)->text());
        self::assertStringContainsString('footwalk west 2 (width=1-2-'.$identity.')', $options->eq(2)->text());
    }

    public function testApproveAsUpdateReplacesApprovedValueWithoutHistory(): void
    {
        $identity = bin2hex(random_bytes(4));
        $client = static::createClient();
        $this->loginUser($client);

        $group = $this->createStreetPart([['way.footwalk.west.3.width', '>2-'.$identity]], $identity);
        $entry = $this->pendingEntry($group, 'way.footwalk.west.3.width', '>2-'.$identity);

        $payload = $this->targetOption($entry, 1); // footwalk west 1
        $this->approveWithTarget($client, $entry, $payload);
        self::assertResponseRedirects();

        $reloaded = $this->reloadGroup($group);
        // The old approved width is gone without a trace (AC3): one
        // approved footwalk west 1 width replaces it, the surface stays, the
        // second footwalk stays, and no footwalk 3 is published.
        self::assertSame([
            'way.footwalk.west.1.surface=asphalt-'.$identity,
            'way.footwalk.west.1.width=>2-'.$identity,
            'way.footwalk.west.2.width=1-2-'.$identity,
            'way.roadway.west.4.lanes=2-'.$identity,
        ], $this->groupTagSummaries($reloaded));
        foreach ($reloaded->getGroupTags() as $groupTag) {
            self::assertTrue($groupTag->isApproved());
        }
    }

    public function testApproveAsUpdateSupersedesCompetingPendingProposalOfProposedKey(): void
    {
        $identity = bin2hex(random_bytes(4));
        $client = static::createClient();
        $this->loginUser($client);

        $group = $this->createStreetPart([
            ['way.footwalk.west.3.width', '>2-'.$identity],
        ], $identity);
        // A second editor's competing pending width proposal for the same
        // proposed element (a different value means a different shared tag).
        $secondEditor = $this->createUser('mapping-editor2-'.$identity.'@example.test', ['editor']);
        $this->addPending($group, $secondEditor, 'way.footwalk.west.3.width', 'x-'.$identity, false);

        $entry = $this->pendingEntry($group, 'way.footwalk.west.3.width', '>2-'.$identity);
        $payload = $this->targetOption($entry, 1);
        $this->approveWithTarget($client, $entry, $payload);
        self::assertResponseRedirects();

        $reloaded = $this->reloadGroup($group);
        // The competing proposal is superseded too (AC5): no unapproved way
        // key remains, and there is no footwalk 3.
        self::assertSame([
            'way.footwalk.west.1.surface=asphalt-'.$identity,
            'way.footwalk.west.1.width=>2-'.$identity,
            'way.footwalk.west.2.width=1-2-'.$identity,
            'way.roadway.west.4.lanes=2-'.$identity,
        ], $this->groupTagSummaries($reloaded));
    }

    public function testApproveAsUpdateAddsMissingAspectOfTarget(): void
    {
        $identity = bin2hex(random_bytes(4));
        $client = static::createClient();
        $this->loginUser($client);

        // footwalk west 2 has an approved width but no surface.
        $group = $this->createStreetPart([['way.footwalk.west.5.surface', 'concrete-'.$identity]], $identity);
        $entry = $this->pendingEntry($group, 'way.footwalk.west.5.surface', 'concrete-'.$identity);

        // Target footwalk west 2: the observation describes that element, so the
        // mapped approve adds the surface aspect to it (AC4 case).
        $payload = $this->targetOption($entry, 2);
        $this->approveWithTarget($client, $entry, $payload);
        self::assertResponseRedirects();

        $reloaded = $this->reloadGroup($group);
        self::assertSame([
            'way.footwalk.west.1.surface=asphalt-'.$identity,
            'way.footwalk.west.1.width=<1-'.$identity,
            'way.footwalk.west.2.surface=concrete-'.$identity,
            'way.footwalk.west.2.width=1-2-'.$identity,
            'way.roadway.west.4.lanes=2-'.$identity,
        ], $this->groupTagSummaries($reloaded));
    }

    public function testApproveAsSubmittedStillAddsTheProposedElement(): void
    {
        $identity = bin2hex(random_bytes(4));
        $client = static::createClient();
        $this->loginUser($client);

        $group = $this->createStreetPart([['way.footwalk.west.3.width', '>2-'.$identity]], $identity);
        $entry = $this->pendingEntry($group, 'way.footwalk.west.3.width', '>2-'.$identity);

        // Default option: approve as submitted without a mapping.
        $crawler = $this->reviewPage($client, $group);
        $button = $crawler->filter(sprintf('form[action="/admin/node-group-tag/%d/approve"]', $entry->getId()))
            ->filter('button[name$="[approve]"]');
        $client->submit($button->form());
        self::assertResponseRedirects();

        $reloaded = $this->reloadGroup($group);
        // Plain approval semantics (AC2): the new element joins the
        // cross-section and the approved footwalks are untouched.
        self::assertSame([
            'way.footwalk.west.1.surface=asphalt-'.$identity,
            'way.footwalk.west.1.width=<1-'.$identity,
            'way.footwalk.west.2.width=1-2-'.$identity,
            'way.footwalk.west.3.width=>2-'.$identity,
            'way.roadway.west.4.lanes=2-'.$identity,
        ], $this->groupTagSummaries($reloaded));
    }

    public function testStaleTargetIsRejectedWithoutChanges(): void
    {
        $identity = bin2hex(random_bytes(4));
        $client = static::createClient();
        $this->loginUser($client);

        $group = $this->createStreetPart([['way.footwalk.west.3.width', '>2-'.$identity]], $identity);
        $entry = $this->pendingEntry($group, 'way.footwalk.west.3.width', '>2-'.$identity);

        // Render the review screen and snapshot the offered option, then
        // change the target's approved width behind the reviewer's back.
        $crawler = $this->reviewPage($client, $group);
        self::assertResponseIsSuccessful();
        $payload = $this->targetOptionValue($crawler, $entry, 1);
        $target = $this->findOrCreateTag('way.footwalk.west.1.width', '<1-'.$identity);
        $target->setValue('changed-'.$identity);
        static::getContainer()->get('doctrine')->getManager()->flush();

        // The stale submit posts raw form values, as a browser would with
        // its already-rendered page.
        $this->postApproveValues($client, $crawler, $entry, $payload);
        self::assertResponseStatusCodeSame(409);

        // Nothing changed: the old value is still there and the observation
        // is still pending (AC7).
        $reloaded = $this->reloadGroup($group);
        self::assertSame([
            'way.footwalk.west.1.surface=asphalt-'.$identity,
            'way.footwalk.west.1.width=changed-'.$identity,
            'way.footwalk.west.2.width=1-2-'.$identity,
            'way.footwalk.west.3.width=>2-'.$identity,
            'way.roadway.west.4.lanes=2-'.$identity,
        ], $this->groupTagSummaries($reloaded));
        $reloadedEntry = null;
        foreach ($reloaded->getGroupTags() as $groupTag) {
            if ($groupTag->getTag()->getValue() === '>2-'.$identity) {
                $reloadedEntry = $groupTag;
            }
        }
        self::assertNotNull($reloadedEntry);
        self::assertFalse($reloadedEntry->isApproved());
    }

    public function testMappingTargetOnUnstructuredObservationIsRejected(): void
    {
        $identity = bin2hex(random_bytes(4));
        $client = static::createClient();
        $this->loginUser($client);

        $group = $this->createStreetPart([['lit', 'yes-'.$identity]], $identity);
        $entry = $this->pendingEntry($group, 'lit', 'yes-'.$identity);

        $crawler = $this->reviewPage($client, $group);
        self::assertResponseIsSuccessful();
        $this->postApproveValues($client, $crawler, $entry, json_encode(['direction' => 'west', 'index' => 1, 'aspectValue' => null]));
        self::assertResponseStatusCodeSame(400);

        $reloaded = $this->reloadGroup($group);
        self::assertSame([
            'lit=yes-'.$identity,
            'way.footwalk.west.1.surface=asphalt-'.$identity,
            'way.footwalk.west.1.width=<1-'.$identity,
            'way.footwalk.west.2.width=1-2-'.$identity,
            'way.roadway.west.4.lanes=2-'.$identity,
        ], $this->groupTagSummaries($reloaded));
    }

    /**
     * Posts a raw approve request for a rendered review row, overriding the
     * mapping target. Used where DOMCrawler option validation would stand in
     * the way of the stale/invalid payloads under test.
     */
    private function postApproveValues(KernelBrowser $client, \Symfony\Component\DomCrawler\Crawler $crawler, NodeGroupTag $entry, string $payload): void
    {
        $form = $crawler
            ->filter(sprintf('form[action="/admin/node-group-tag/%d/approve"]', $entry->getId()))
            ->form();
        $values = $form->getPhpValues();
        $values['target_index'] = $payload;
        $client->request('POST', '/admin/node-group-tag/'.$entry->getId().'/approve', $values);
    }

    /**
     * Extracts a mapping option value from a rendered review page.
     */
    private function targetOptionValue(\Symfony\Component\DomCrawler\Crawler $crawler, NodeGroupTag $entry, int $position): string
    {
        return $crawler
            ->filter(sprintf('form[action="/admin/node-group-tag/%d/approve"]', $entry->getId()))
            ->filter('select[name="target_index"] option')
            ->eq($position)
            ->attr('value');
    }

    public function testMappedApprovalPublishesTranslationsUnderEffectiveKeyOnly(): void
    {
        $identity = bin2hex(random_bytes(4));
        // Isolate the published catalogs in a throwaway copy.
        $directory = sys_get_temp_dir().'/aspect-mapping-translations-'.$identity;
        mkdir($directory);
        foreach (['en', 'de'] as $locale) {
            copy(\dirname(__DIR__).'/Fixtures/tag-translations/tags.'.$locale.'.xlf', $directory.'/tags.'.$locale.'.xlf');
        }
        try {
            $client = static::createClient();
            // Keep the swapped catalog across the client's requests.
            $client->disableReboot();
            // Swap the catalog to the throwaway copy once the kernel runs.
            static::getContainer()->set(TagTranslationCatalog::class, new TagTranslationCatalog($directory));
            $this->loginUser($client);

            $group = $this->createStreetPart([['way.footwalk.west.3.width', '>2-'.$identity]], $identity);
            $entry = $this->pendingEntry($group, 'way.footwalk.west.3.width', '>2-'.$identity);
            // The contributor's proposed labels ride along with the observation.
            $entry->getTag()->setTranslations(['de' => ['keyTranslation' => 'Breite '.$identity, 'valueTranslation' => 'Über 2 m '.$identity]]);
            static::getContainer()->get('doctrine')->getManager()->flush();

            $payload = $this->targetOption($entry, 1);
            $this->approveWithTarget($client, $entry, $payload);
            self::assertResponseRedirects();

            // Reviewed labels publish under the effective key, and no catalog
            // unit exists for the proposed element key (AC8).
            $de = (string) file_get_contents($directory.'/tags.de.xlf');
            self::assertStringContainsString('key:way.footwalk.west.1.width', $de);
            self::assertStringContainsString('Breite '.$identity, $de);
            self::assertStringNotContainsString('way.footwalk.west.3.width', $de);
        } finally {
            foreach (['en', 'de'] as $locale) {
                @unlink($directory.'/tags.'.$locale.'.xlf');
            }
            @rmdir($directory);
        }
    }

    private function pendingEntry(NodeGroup $group, string $key, string $value): NodeGroupTag
    {
        foreach ($group->getGroupTags() as $groupTag) {
            if (!$groupTag->isApproved()
                && $groupTag->getTag()->getKey() === $key
                && $groupTag->getTag()->getValue() === $value) {
                return $groupTag;
            }
        }
        self::fail('Pending entry not found: '.$key.'='.$value);
    }
}
