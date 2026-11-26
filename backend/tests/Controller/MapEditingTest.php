<?php

namespace App\Tests\Controller;

use App\Entity\{NodeGroup, Node, NodeGroupTag};
use App\Service\{AspectCatalog, StreetPartAspects, Routing, WayObservationVocabulary, EditingVocabulary};
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class MapEditingTest extends WebTestCase
{
    use CreatesUsers;
    private array $groups = [];
    private array $nodes = [];

    protected function tearDown(): void
    {
        $em = static::getContainer()->get('doctrine')->getManager();
        if (!$em->isOpen()) $em = static::getContainer()->get('doctrine')->resetManager();
        foreach ([NodeGroup::class => $this->groups, Node::class => $this->nodes] as $class => $ids) {
            foreach ($ids as $id) {
                if ($entry = $em->find($class, $id)) $em->remove($entry);
            }
            $em->flush();
        }
        parent::tearDown();
    }

    /**
     * Creates one observation relation (specs/map-editing/observation-
     * relations/spec.md): its own member range plus exactly one tag.
     */
    private function createPart($client, array $tag, ?array $members = null): array
    {
        $suffix = bin2hex(random_bytes(6));
        $members ??= [['providerNodeId' => 'n'.$suffix.'1', 'latitude' => 54.791, 'longitude' => 9.437], ['providerNodeId' => 'n'.$suffix.'2', 'latitude' => 54.792, 'longitude' => 9.438]];
        $client->jsonRequest('POST', '/api/node-groups', ['members' => $members, 'tags' => [$tag]]);
        self::assertResponseStatusCodeSame(201);
        $part = json_decode($client->getResponse()->getContent(), true);
        $this->groups[] = $part['id'];
        foreach ($part['members'] as $member) $this->nodes[] = $member['id'];
        return $part;
    }

    public function testOneObservationPerRelationVisibilityModerationAndRouting(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser('structured-editor@example.test', ['editor']));
        $members = [['providerNodeId' => 'n'.bin2hex(random_bytes(4)).'1', 'latitude' => 54.791, 'longitude' => 9.437], ['providerNodeId' => 'n'.bin2hex(random_bytes(4)).'2', 'latitude' => 54.792, 'longitude' => 9.438]];

        // Two observations for the same picked range create two relations.
        $surface = $this->createPart($client, ['key' => 'sidewalk.left.surface', 'value' => 'asphalt'], $members);
        $kerb = $this->createPart($client, ['key' => 'sidewalk.left.kerb', 'value' => '2-3'], $members);
        self::assertNotSame($surface['id'], $kerb['id']);
        self::assertSame([['key' => 'sidewalk.left.surface', 'value' => 'asphalt']], $surface['observations']);
        self::assertSame(array_column($members, 'providerNodeId'), array_column($surface['members'], 'providerNodeId'));

        $preference = 'aspect.sidewalk-asphalt';
        self::assertSame([], static::getContainer()->get(StreetPartAspects::class)->routingAreas([$preference]));

        // Pending observation relations are only visible to their creator.
        $client->loginUser($this->createUser('structured-other@example.test', ['editor']));
        $client->request('GET', '/api/node-groups');
        self::assertNotContains($kerb['id'], array_column(json_decode($client->getResponse()->getContent(), true), 'id'));

        // The review queue lists the pending observation; approving it
        // publishes exactly that observation.
        $client->loginUser($this->createUser('structured-admin@example.test', ['admin']), 'admin');
        $em = static::getContainer()->get('doctrine')->getManager();
        $group = $em->find(NodeGroup::class, $surface['id']);
        $approvedId = null;
        foreach ($group->getGroupTags() as $entry) {
            if ($entry->getTag()->getKey() === 'sidewalk.left.surface') $approvedId = $entry->getId();
        }
        $url = static::getContainer()->get('EasyCorp\\Bundle\\EasyAdminBundle\\Router\\AdminUrlGenerator')->setController(\App\Controller\Admin\PendingRelationObservationCrudController::class)->setAction('edit')->setEntityId($surface['id'])->generateUrl();
        $crawler = $client->request('GET', $url);
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('form[action="/admin/node-group-tag/'.$approvedId.'/approve"]')->selectButton('Approve')->form();
        $client->submit($form);
        self::assertResponseRedirects();
        $areas = static::getContainer()->get(StreetPartAspects::class)->routingAreas([$preference]);
        self::assertContains($surface['id'], array_column($areas[$preference], 'id'));
        $routing = static::getContainer()->get(Routing::class);
        $model = $routing->customModelFor([$preference => 'prefer'], [], [], $areas);
        self::assertStringStartsWith('!in_', $model['priority'][0]['if']);
        $avoid = $routing->customModelFor([$preference => 'avoid'], [], [], $areas);
        self::assertGreaterThan(0, (float) $avoid['priority'][0]['multiply_by']);
        self::assertLessThan(1, (float) $avoid['priority'][0]['multiply_by']);

        // Anonymous visitors see the approved observation, but the other
        // editor's still-pending observation stays invisible.
        $client->request('POST', '/api/logout');
        $client->request('GET', '/api/node-groups');
        $all = json_decode($client->getResponse()->getContent(), true);
        $published = array_column($all, null, 'id')[$surface['id']] ?? null;
        self::assertNotNull($published);
        self::assertSame([['key' => 'sidewalk.left.surface', 'value' => 'asphalt']], $published['tags']);
        self::assertSame([], $published['observations']);
        self::assertNotContains($kerb['id'], array_column($all, 'id'));

        // Approval freezes the observation relation for editors; the value
        // cannot be edited anymore. The pending observation can still be
        // edited by its creator.
        $client->loginUser($this->createUser('structured-editor@example.test', ['editor']));
        $client->jsonRequest('POST', '/api/node-groups', ['id' => $surface['id'], 'tags' => [['key' => 'sidewalk.left.surface', 'value' => 'concrete']]]);
        self::assertResponseStatusCodeSame(422);
        $client->jsonRequest('POST', '/api/node-groups', ['id' => $kerb['id'], 'tags' => [['key' => 'sidewalk.left.kerb', 'value' => '3-4']]]);
        self::assertResponseIsSuccessful();
        self::assertSame([['key' => 'sidewalk.left.kerb', 'value' => '3-4']], json_decode($client->getResponse()->getContent(), true)['observations']);
        $client->jsonRequest('PUT', '/api/tag-priorities', ['priorities' => [$preference => 'prefer']]);
        self::assertResponseIsSuccessful();
        $client->request('GET', '/api/tag-priorities');
        self::assertSame('prefer', json_decode($client->getResponse()->getContent(), true)['priorities'][$preference]);
    }

    public function testRoutingMatchesOnlyWithinTheObservationRangeAndAspect(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser('structured-routing-admin@example.test', ['admin']));
        $match = $this->createPart($client, ['key' => 'sidewalk.left.surface', 'value' => 'asphalt']);
        $missOtherType = $this->createPart($client, ['key' => 'roadway.left.surface', 'value' => 'asphalt']);
        $missOtherAspect = $this->createPart($client, ['key' => 'sidewalk.left.kerb', 'value' => '2-3']);
        $em = static::getContainer()->get('doctrine')->getManager();
        foreach ([$match['id'], $missOtherType['id'], $missOtherAspect['id']] as $id) {
            foreach ($em->find(NodeGroup::class, $id)->getGroupTags() as $entry) $entry->setApproved(true);
        }
        $em->flush();
        $areas = static::getContainer()->get(StreetPartAspects::class)->routingAreas(['aspect.sidewalk-asphalt']);
        $ids = array_column($areas['aspect.sidewalk-asphalt'], 'id');
        self::assertContains($match['id'], $ids);
        self::assertNotContains($missOtherType['id'], $ids);
        // An unobserved aspect never acts as an alternative: a sidewalk
        // without any surface observation still matches the preference.
        self::assertContains($missOtherAspect['id'], $ids);
    }

    public function testSurfacePreferenceMatchesTheSingleObservedValue(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser('structured-surface-admin@example.test', ['admin']));
        $preference = 'aspect.sidewalk-paving-stones';
        $onlyPaving = $this->createPart($client, ['key' => 'sidewalk.left.surface', 'value' => 'paving_stones']);
        $asphalt = $this->createPart($client, ['key' => 'sidewalk.left.surface', 'value' => 'asphalt']);
        $unknownSurface = $this->createPart($client, ['key' => 'sidewalk.left.kerb', 'value' => '2-3']);
        $noSidewalk = $this->createPart($client, ['key' => 'roadway.left.surface', 'value' => 'paving_stones']);
        $em = static::getContainer()->get('doctrine')->getManager();
        foreach ([$onlyPaving['id'], $asphalt['id'], $unknownSurface['id'], $noSidewalk['id']] as $id) {
            foreach ($em->find(NodeGroup::class, $id)->getGroupTags() as $entry) $entry->setApproved(true);
        }
        $em->flush();
        $areas = static::getContainer()->get(StreetPartAspects::class)->routingAreas([$preference]);
        $ids = array_column($areas[$preference] ?? [], 'id');
        // A relation is one observed value: it matches when that value is a
        // selected one, or when the aspect was not observed at all (an
        // unobserved aspect never acts as an alternative); a different
        // observed surface never matches, and without a sidewalk there is
        // nothing to rate.
        self::assertContains($onlyPaving['id'], $ids);
        self::assertContains($unknownSurface['id'], $ids);
        self::assertNotContains($asphalt['id'], $ids);
        self::assertNotContains($noSidewalk['id'], $ids);
        $routing = static::getContainer()->get(Routing::class);
        $avoid = $routing->customModelFor([$preference => 'avoid'], [], [], $areas);
        self::assertSame('0.1', $avoid['priority'][0]['multiply_by']);
        self::assertStringStartsWith('in_', $avoid['priority'][0]['if']);
        $asphaltAreas = static::getContainer()->get(StreetPartAspects::class)->routingAreas(['aspect.sidewalk-asphalt']);
        self::assertContains($asphalt['id'], array_column($asphaltAreas['aspect.sidewalk-asphalt'], 'id'));
        self::assertNotContains($onlyPaving['id'], array_column($asphaltAreas['aspect.sidewalk-asphalt'], 'id'));
    }

    public function testAnAlternativeSidewalkOnAnotherRelationExemptsTheMatchedCorridor(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser('structured-engulfed-admin@example.test', ['admin']));
        // One relation observes the left sidewalk as asphalt, another
        // covers the same section with a non-asphalt right sidewalk: the
        // section offers a comfortable alternative, so the asphalt
        // preference must not penalize it.
        $suffix = bin2hex(random_bytes(4));
        $members = [['providerNodeId' => 'n'.$suffix.'1', 'latitude' => 54.791, 'longitude' => 9.437], ['providerNodeId' => 'n'.$suffix.'2', 'latitude' => 54.792, 'longitude' => 9.438]];
        $asphalt = $this->createPart($client, ['key' => 'sidewalk.left.surface', 'value' => 'asphalt'], $members);
        $alternative = $this->createPart($client, ['key' => 'sidewalk.right.surface', 'value' => 'paving_stones'], $members);
        $em = static::getContainer()->get('doctrine')->getManager();
        foreach ([$asphalt['id'], $alternative['id']] as $id) {
            foreach ($em->find(NodeGroup::class, $id)->getGroupTags() as $entry) $entry->setApproved(true);
        }
        $em->flush();

        // The alternative's corridor covers the matched corridor entirely:
        // nothing remains, so the preference produces no area and no custom
        // model statement at all.
        $aspects = static::getContainer()->get(StreetPartAspects::class);
        $areas = $aspects->routingAreas(['aspect.sidewalk-asphalt']);
        self::assertSame([], $areas);
        self::assertNull(static::getContainer()->get(Routing::class)->customModelFor(['aspect.sidewalk-asphalt' => 'avoid'], [], [], $areas));

        // Without the alternative observation the corridor matches again.
        foreach ($em->find(NodeGroup::class, $alternative['id'])->getGroupTags() as $entry) $entry->setApproved(false);
        $em->flush();
        self::assertSame([$asphalt['id']], array_column($aspects->routingAreas(['aspect.sidewalk-asphalt'])['aspect.sidewalk-asphalt'], 'id'));
    }

    public function testAPartiallyOverlappingAlternativeClipsTheCorridorToTheUncoveredStretch(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser('structured-clip-admin@example.test', ['admin']));
        $suffix = bin2hex(random_bytes(4));
        $long = [['providerNodeId' => 'n'.$suffix.'1', 'latitude' => 54.791, 'longitude' => 9.437], ['providerNodeId' => 'n'.$suffix.'2', 'latitude' => 54.792, 'longitude' => 9.438], ['providerNodeId' => 'n'.$suffix.'3', 'latitude' => 54.793, 'longitude' => 9.439]];
        $asphalt = $this->createPart($client, ['key' => 'sidewalk.left.surface', 'value' => 'asphalt'], $long);
        $alternative = $this->createPart($client, ['key' => 'sidewalk.right.surface', 'value' => 'paving_stones'], [['providerNodeId' => 'n'.$suffix.'2', 'latitude' => 54.792, 'longitude' => 9.438], ['providerNodeId' => 'n'.$suffix.'3', 'latitude' => 54.793, 'longitude' => 9.439]]);
        $em = static::getContainer()->get('doctrine')->getManager();
        foreach ([$asphalt['id'], $alternative['id']] as $id) {
            foreach ($em->find(NodeGroup::class, $id)->getGroupTags() as $entry) $entry->setApproved(true);
        }
        $em->flush();

        // The alternative covers only the second half of the asphalt range:
        // the clipped corridor keeps exactly the stretch without one.
        $areas = static::getContainer()->get(StreetPartAspects::class)->routingAreas(['aspect.sidewalk-asphalt']);
        $ids = array_column($areas['aspect.sidewalk-asphalt'], 'id');
        self::assertContains($asphalt['id'], $ids);
        $maxLatitude = 0.0;
        $geometry = array_values(array_filter($areas['aspect.sidewalk-asphalt'], static fn (array $area): bool => $area['id'] === $asphalt['id']))[0]['area'];
        array_walk_recursive($geometry['coordinates'], static function (float $coordinate) use (&$maxLatitude): void {
            $maxLatitude = max($maxLatitude, $coordinate);
        });
        self::assertGreaterThan(54.791, $maxLatitude);
        self::assertLessThan(54.7925, $maxLatitude);
    }

    public function testSidesAreIndependentObservationsRedefined(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser('structured-sides@example.test', ['editor']));
        $members = [['providerNodeId' => 'n'.bin2hex(random_bytes(4)).'1', 'latitude' => 54.791, 'longitude' => 9.437], ['providerNodeId' => 'n'.bin2hex(random_bytes(4)).'2', 'latitude' => 54.792, 'longitude' => 9.438]];
        $left = $this->createPart($client, ['key' => 'sidewalk.left.kerb', 'value' => '2-3'], $members);
        $right = $this->createPart($client, ['key' => 'sidewalk.right.kerb', 'value' => '3-4'], $members);
        // Sides live on their own relations; both are ordinary observations.
        self::assertSame([['key' => 'sidewalk.left.kerb', 'value' => '2-3']], $left['tags']);
        self::assertSame([['key' => 'sidewalk.right.kerb', 'value' => '3-4']], $right['tags']);
    }

    public function testGrammarAndNearGrammarPayloadsAreRejected(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser('structured-validation@example.test', ['editor']));
        $part = $this->createPart($client, ['key' => 'sidewalk.left.width', 'value' => '<80']);
        $badKeys = [
            'unknown.left.width', // structured shape, type outside the catalog
            'sidewalk.left.1.width', // the former indexed grammar shape
            'sidewalk.left.width.extra', // extra segment
            '.left.width', // empty type
            'sidewalk.left.unknown', // aspect outside the type's catalog
        ];
        foreach ($badKeys as $key) {
            $client->jsonRequest('POST', '/api/node-groups', ['id' => $part['id'], 'name' => 'must roll back', 'tags' => [['key' => $key, 'value' => '<80']]]);
            self::assertResponseStatusCodeSame(422);
        }
        // A second tag on one relation is the composed cross-section coming
        // back: rejected under one observation = one relation.
        $client->jsonRequest('POST', '/api/node-groups', ['id' => $part['id'], 'tags' => [['key' => 'sidewalk.left.width', 'value' => '<80'], ['key' => 'sidewalk.right.width', 'value' => '>200']]]);
        self::assertResponseStatusCodeSame(422);
        // Values outside the catalog are rejected atomically.
        $client->jsonRequest('POST', '/api/node-groups', ['id' => $part['id'], 'name' => 'must roll back', 'tags' => [['key' => 'sidewalk.left.width', 'value' => '2.5']]]);
        self::assertResponseStatusCodeSame(422);
        $client->request('GET', '/api/node-groups');
        $reloaded = array_values(array_filter(json_decode($client->getResponse()->getContent(), true), fn ($g) => $g['id'] === $part['id']))[0];
        self::assertNull($reloaded['name']);
        self::assertSame($part['tags'], $reloaded['tags']);
        $tables = static::getContainer()->get('doctrine')->getConnection()->createSchemaManager()->listTableNames();
        self::assertSame([], array_values(array_intersect(['aspect_types', 'aspect_attributes', 'aspect_preferences', 'street_part_aspects'], $tables)));
    }

    public function testFormerGrammarShapesAreOrdinaryTags(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser('structured-plain@example.test', ['editor']));
        // A key only declares a cross-section position when a side token sits
        // in the second dot segment; other shapes are plain tags, each on
        // its own relation like every observation.
        $plainKeys = [
            'way.sidewalk.left.width', // removed namespace prefix, no side in segment two
            'sidewalk.1.width', // side-less grammar
            'sidewalk.west.width', // compass sides are no side tokens here
            'sidewalk.LEFT.width', // side tokens are lowercase
            'sidewalk:left:width', // colon-separated OSM-style key
        ];
        foreach ($plainKeys as $key) {
            $part = $this->createPart($client, ['key' => $key, 'value' => 'plain']);
            self::assertSame([['key' => $key, 'value' => 'plain']], $part['tags']);
        }
    }

    public function testRetiredValuesAndCatalogFailurePreserveData(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $client->loginUser($this->createUser('structured-retired@example.test', ['editor']));
        $path = tempnam(sys_get_temp_dir(), 'aspect-catalog');
        $definitions = json_decode(file_get_contents(dirname(__DIR__, 2).'/config/aspect-catalog.json'), true);
        file_put_contents($path, json_encode($definitions));
        static::getContainer()->set(AspectCatalog::class, new AspectCatalog($path, new \Psr\Log\NullLogger()));
        try {
            $part = $this->createPart($client, ['key' => 'sidewalk.left.surface', 'value' => 'paving_stones']);
            // Retire the stored value from the catalog.
            foreach ($definitions['types'] as &$type) {
                if ($type['name'] !== 'sidewalk') {
                    continue;
                }
                foreach ($type['attributes'] as &$attribute) {
                    if ($attribute['key'] === 'surface') {
                        $attribute['values'] = ['asphalt', 'concrete'];
                    }
                }
                unset($attribute);
            }
            unset($type);
            file_put_contents($path, json_encode($definitions));
            // An unchanged echo of the relation's own observation stays valid.
            $client->jsonRequest('POST', '/api/node-groups', ['id' => $part['id'], 'tags' => $part['tags']]);
            self::assertResponseIsSuccessful();
            $em = static::getContainer()->get('doctrine')->getManager();
            $group = $em->find(NodeGroup::class, $part['id']);
            foreach ($group->getGroupTags() as $entry) $entry->setApproved(true);
            $em->flush();
            // The stored retired value keeps matching existing preferences.
            self::assertContains($part['id'], array_column(static::getContainer()->get(StreetPartAspects::class)->routingAreas(['aspect.sidewalk-paving-stones'])['aspect.sidewalk-paving-stones'], 'id'));
            // A retired value cannot be observed on a new observation relation.
            $client->loginUser($this->createUser('structured-retired-other@example.test', ['editor']));
            $client->jsonRequest('POST', '/api/node-groups', ['members' => [['providerNodeId' => 'n-'.bin2hex(random_bytes(4)), 'latitude' => 54.791, 'longitude' => 9.437], ['providerNodeId' => 'n-'.bin2hex(random_bytes(4)), 'latitude' => 54.792, 'longitude' => 9.438]], 'tags' => [['key' => 'sidewalk.left.surface', 'value' => 'paving_stones']]]);
            self::assertResponseStatusCodeSame(422);
            file_put_contents($path, '{');
            $client->request('GET', '/api/editing-catalog');
            self::assertResponseStatusCodeSame(503);
            $client->jsonRequest('POST', '/api/node-groups', ['members' => [['providerNodeId' => 'n-'.bin2hex(random_bytes(4)), 'latitude' => 54.791, 'longitude' => 9.437], ['providerNodeId' => 'n-'.bin2hex(random_bytes(4)), 'latitude' => 54.792, 'longitude' => 9.438]], 'tags' => [['key' => 'sidewalk.left.surface', 'value' => 'asphalt']]]);
            self::assertResponseStatusCodeSame(503);
        } finally {
            unlink($path);
        }
    }

    public function testPublicCatalogAndUnavailableResponse(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/editing-catalog');
        self::assertResponseIsSuccessful();
        self::assertArrayHasKey('footway', json_decode($client->getResponse()->getContent(), true)['wayVocabularies']);
        $client->disableReboot();
        // Reboot once before replacing the previously initialized reader.
        static::ensureKernelShutdown();
        $client = static::createClient();
        static::getContainer()->set(EditingVocabulary::class, new EditingVocabulary('/missing/vocabulary.json', new \Psr\Log\NullLogger()));
        $client->request('GET', '/api/editing-catalog');
        self::assertResponseStatusCodeSame(503);
        self::assertSame(['error' => 'editing_vocabulary_unavailable'], json_decode($client->getResponse()->getContent(), true));
    }

    public function testWholeWayObservationRelationsFollowTheWayVocabulary(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $client->loginUser($this->createUser('way-vocabulary-editor@example.test', ['editor']));
        $temp = sys_get_temp_dir() . '/way-fixture-' . bin2hex(random_bytes(4));
        mkdir($temp . '/osm-data', recursive: true);
        file_put_contents($temp . '/osm-data/flensburg.geojson', json_encode(['features' => [['id' => 'w999999999', 'properties' => ['highway' => 'test_footway']], ['id' => 'w999999998', 'properties' => ['highway' => 'test_residential']]]]));
        $vocabularyFile = $temp . '/vocabulary.json';
        file_put_contents($vocabularyFile, json_encode(['way' => ['test_footway' => [['key' => 'surface', 'values' => ['asphalt', 'uneven']]]]]));
        $vocabulary = new EditingVocabulary($vocabularyFile, new \Psr\Log\NullLogger());
        static::getContainer()->set(WayObservationVocabulary::class, new WayObservationVocabulary($vocabulary, $temp));
        try {
            // A whole-way observation is a single-member relation anchored on
            // the way identity node; the way vocabulary restricts its key.
            $client->jsonRequest('POST', '/api/node-groups', ['members' => [['providerNodeId' => 'w999999999', 'latitude' => 54.79, 'longitude' => 9.43]], 'tags' => [['key' => 'surface', 'value' => 'asphalt']]]);
            self::assertResponseStatusCodeSame(201);
            $way = json_decode($client->getResponse()->getContent(), true);
            $this->groups[] = $way['id'];
            $this->nodes[] = $way['members'][0]['id'];
            self::assertCount(1, $way['members']);
            self::assertNull($way['area']);
            $client->jsonRequest('POST', '/api/node-groups', ['members' => [['providerNodeId' => 'w999999999', 'latitude' => 54.79, 'longitude' => 9.43]], 'tags' => [['key' => 'unknown', 'value' => 'yes']]]);
            self::assertResponseStatusCodeSame(422);
            $client->jsonRequest('POST', '/api/node-groups', ['members' => [['providerNodeId' => 'w999999999', 'latitude' => 54.79, 'longitude' => 9.43]], 'tags' => [['key' => 'surface', 'value' => 'gravel']]]);
            self::assertResponseStatusCodeSame(422);
            // An untyped (empty-vocabulary) way is unobservable.
            $client->jsonRequest('POST', '/api/node-groups', ['members' => [['providerNodeId' => 'w999999998', 'latitude' => 54.79, 'longitude' => 9.43]], 'tags' => [['key' => 'surface', 'value' => 'asphalt']]]);
            self::assertResponseStatusCodeSame(422);
        } finally {
            unlink($vocabularyFile);
            unlink($temp . '/osm-data/flensburg.geojson');
            rmdir($temp . '/osm-data');
            rmdir($temp);
        }
    }
}
