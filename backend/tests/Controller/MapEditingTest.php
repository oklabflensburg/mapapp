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

    private function createPart($client, array $tags): array
    {
        $suffix = bin2hex(random_bytes(6));
        $client->jsonRequest('POST', '/api/node-groups', ['members' => [['providerNodeId' => 'n'.$suffix.'1', 'latitude' => 54.791, 'longitude' => 9.437], ['providerNodeId' => 'n'.$suffix.'2', 'latitude' => 54.792, 'longitude' => 9.438]], 'tags' => $tags]);
        self::assertResponseStatusCodeSame(201);
        $part = json_decode($client->getResponse()->getContent(), true);
        $this->groups[] = $part['id'];
        foreach ($part['members'] as $member) $this->nodes[] = $member['id'];
        return $part;
    }

    public function testTagStorageVisibilityExactKeyModerationAndRouting(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser('structured-editor@example.test', ['editor']));
        $tags = [
            ['key' => 'way.footwalk.1.width', 'value' => '>2'],
            ['key' => 'way.footwalk.1.surface', 'value' => 'asphalt'],
            ['key' => 'way.footwalk.5.width', 'value' => '<1'],
            ['key' => 'note', 'value' => 'ordinary tag'],
        ];
        $part = $this->createPart($client, $tags);
        self::assertArrayNotHasKey('aspects', $part);
        self::assertSame($tags, $part['observations']);
        self::assertFalse($part['approved']);
        $preference = 'aspect:footwalk-wide';
        self::assertSame([], static::getContainer()->get(StreetPartAspects::class)->routingAreas([$preference]));
        $client->loginUser($this->createUser('structured-other@example.test', ['editor']));
        $client->request('GET', '/api/node-groups');
        self::assertNotContains($part['id'], array_column(json_decode($client->getResponse()->getContent(), true), 'id'));
        $client->jsonRequest('POST', '/api/node-groups', ['id' => $part['id'], 'tags' => [['key' => 'way.footwalk.1.width', 'value' => '<1']]]);
        self::assertResponseIsSuccessful();
        $client->loginUser($this->createUser('structured-admin@example.test', ['admin']), 'admin');
        $em = static::getContainer()->get('doctrine')->getManager();
        $group = $em->find(NodeGroup::class, $part['id']);
        $approvedId = null;
        foreach ($group->getGroupTags() as $entry) {
            if ($entry->getTag()->getKey() === 'way.footwalk.1.width' && $entry->getTag()->getValue() === '>2') $approvedId = $entry->getId();
        }
        $url = static::getContainer()->get('EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator')->setController(\App\Controller\Admin\PendingRelationObservationCrudController::class)->setAction('edit')->setEntityId($part['id'])->generateUrl();
        $crawler = $client->request('GET', $url);
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('form[action="/admin/node-group-tag/'.$approvedId.'/approve"]')->selectButton('Approve')->form();
        $client->submit($form);
        self::assertResponseRedirects();
        $areas = static::getContainer()->get(StreetPartAspects::class)->routingAreas([$preference]);
        self::assertContains($part['id'], array_column($areas[$preference], 'id'));
        $routing = static::getContainer()->get(Routing::class);
        $model = $routing->customModelFor([$preference => 'prefer'], [], [], $areas);
        self::assertStringStartsWith('!in_', $model['priority'][0]['if']);
        $avoid = $routing->customModelFor([$preference => 'avoid'], [], [], $areas);
        self::assertGreaterThan(0, (float) $avoid['priority'][0]['multiply_by']);
        self::assertLessThan(1, (float) $avoid['priority'][0]['multiply_by']);
        $client->request('POST', '/api/logout');
        $client->request('GET', '/api/node-groups');
        $published = array_values(array_filter(json_decode($client->getResponse()->getContent(), true), fn ($g) => $g['id'] === $part['id']))[0];
        self::assertSame([$tags[0]], $published['tags']);
        self::assertSame([], $published['observations']);
        $client->loginUser($this->createUser('structured-editor@example.test', ['editor']));
        $client->jsonRequest('POST', '/api/node-groups', ['id' => $part['id'], 'tags' => []]);
        self::assertResponseIsSuccessful();
        self::assertSame([$tags[0]], json_decode($client->getResponse()->getContent(), true)['tags']);
        $client->jsonRequest('POST', '/api/node-groups', ['id' => $part['id'], 'tags' => [['key' => $tags[0]['key'], 'value' => '<1']]]);
        self::assertResponseStatusCodeSame(422);
        $client->jsonRequest('POST', '/api/node-groups', ['id' => $part['id'], 'tags' => [$tags[0], ['key' => 'way.footwalk.5.surface', 'value' => 'concrete']]]);
        self::assertResponseIsSuccessful();
        self::assertSame([['key' => 'way.footwalk.5.surface', 'value' => 'concrete']], json_decode($client->getResponse()->getContent(), true)['observations']);
        $client->jsonRequest('PUT', '/api/tag-priorities', ['priorities' => [$preference => 'prefer']]);
        self::assertResponseIsSuccessful();
        $client->request('GET', '/api/tag-priorities');
        self::assertSame('prefer', json_decode($client->getResponse()->getContent(), true)['priorities'][$preference]);
    }

    public function testRoutingMatchesTypeAndAspectAtAnyIndex(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser('structured-routing-admin@example.test', ['admin']));
        $match = $this->createPart($client, [
            ['key' => 'way.footwalk.1.width', 'value' => '<1'],
            ['key' => 'way.footwalk.10.width', 'value' => '>2'],
        ]);
        $miss = $this->createPart($client, [
            ['key' => 'way.roadway.1.width', 'value' => '>2'],
            ['key' => 'way.footwalk.10.surface', 'value' => 'asphalt'],
        ]);
        $areas = static::getContainer()->get(StreetPartAspects::class)->routingAreas(['aspect:footwalk-wide']);
        $ids = array_column($areas['aspect:footwalk-wide'], 'id');
        self::assertContains($match['id'], $ids);
        self::assertNotContains($miss['id'], $ids);
    }

    public function testSurfacePreferencesOnlyMatchWithoutAnObservedAlternative(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser('structured-surface-admin@example.test', ['admin']));
        $preference = 'aspect:footwalk-paving-stones';
        $onlyPaving = $this->createPart($client, [
            ['key' => 'way.footwalk.1.surface', 'value' => 'paving_stones'],
        ]);
        $withAlternative = $this->createPart($client, [
            ['key' => 'way.footwalk.1.surface', 'value' => 'paving_stones'],
            ['key' => 'way.footwalk.2.surface', 'value' => 'asphalt'],
        ]);
        $withUnknown = $this->createPart($client, [
            ['key' => 'way.footwalk.1.surface', 'value' => 'paving_stones'],
            ['key' => 'way.footwalk.2.width', 'value' => '<1'],
        ]);
        $noFootwalk = $this->createPart($client, [
            ['key' => 'way.roadway.1.surface', 'value' => 'paving_stones'],
            ['key' => 'way.cycle_lane.2.surface', 'value' => 'paving_stones'],
        ]);
        $areas = static::getContainer()->get(StreetPartAspects::class)->routingAreas([$preference]);
        $ids = array_column($areas[$preference] ?? [], 'id');
        // Unknown values are no alternatives: only an explicitly observed
        // non-selected surface, or no footwalk at all, exempts a part.
        self::assertContains($onlyPaving['id'], $ids);
        self::assertContains($withUnknown['id'], $ids);
        self::assertNotContains($withAlternative['id'], $ids);
        self::assertNotContains($noFootwalk['id'], $ids);
        // The same part without the paving-stones surface but with a footwalk
        // matches, and rating it avoid shapes the custom model like any
        // aspect preference does.
        $unknownOnly = $this->createPart($client, [
            ['key' => 'way.footwalk.1.width', 'value' => '<1'],
        ]);
        $areas = static::getContainer()->get(StreetPartAspects::class)->routingAreas([$preference]);
        self::assertContains($unknownOnly['id'], array_column($areas[$preference], 'id'));
        $routing = static::getContainer()->get(Routing::class);
        $avoid = $routing->customModelFor([$preference => 'avoid'], [], [], $areas);
        self::assertSame('0.1', $avoid['priority'][0]['multiply_by']);
        self::assertStringStartsWith('in_', $avoid['priority'][0]['if']);
        $asphaltAreas = static::getContainer()->get(StreetPartAspects::class)->routingAreas(['aspect:footwalk-asphalt']);
        self::assertNotContains($withAlternative['id'], array_column($asphaltAreas['aspect:footwalk-asphalt'], 'id'));
    }

    public function testGrammarAndLegacyPayloadsAreRejectedAtomically(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser('structured-validation@example.test', ['editor']));
        $part = $this->createPart($client, [['key' => 'way.footwalk.1.width', 'value' => '<1']]);
        $badKeys = ['way.footwalk.0.width', 'way.footwalk.01.width', 'way.footwalk.-1.width', 'way.footwalk.1', 'way.footwalk.1.width.extra', 'way..1.width', 'way.unknown.1.width', 'way.footwalk.1.unknown'];
        foreach ($badKeys as $key) {
            $client->jsonRequest('POST', '/api/node-groups', ['id' => $part['id'], 'name' => 'must roll back', 'tags' => [['key' => $key, 'value' => '<1']]]);
            self::assertResponseStatusCodeSame(422);
        }
        foreach ([
            [['key' => 'way.footwalk.1.width', 'value' => '2.5']],
            [['key' => 'way.footwalk.1.width', 'value' => '<1'], ['key' => 'way.footwalk.1.width', 'value' => '>2']],
            [['key' => 'way.footwalk.1.width', 'value' => '<1'], ['key' => 'way.roadway.1.width', 'value' => '>2']],
        ] as $tags) {
            $client->jsonRequest('POST', '/api/node-groups', ['id' => $part['id'], 'name' => 'must roll back', 'tags' => $tags]);
            self::assertResponseStatusCodeSame(422);
        }
        foreach ([[], null] as $legacy) {
            $client->jsonRequest('POST', '/api/node-groups', ['id' => $part['id'], 'name' => 'must roll back', 'aspects' => $legacy]);
            self::assertResponseStatusCodeSame(422);
        }
        $client->request('GET', '/api/node-groups');
        $reloaded = array_values(array_filter(json_decode($client->getResponse()->getContent(), true), fn ($g) => $g['id'] === $part['id']))[0];
        self::assertNull($reloaded['name']);
        self::assertSame($part['tags'], $reloaded['tags']);
        $tables = static::getContainer()->get('doctrine')->getConnection()->createSchemaManager()->listTableNames();
        self::assertSame([], array_values(array_intersect(['aspect_types', 'aspect_attributes', 'aspect_preferences', 'street_part_aspects'], $tables)));
    }

    public function testRetiredValuesAndCatalogFailurePreserveData(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $client->loginUser($this->createUser('structured-retired@example.test', ['editor']));
        $path = tempnam(sys_get_temp_dir(), 'aspect-catalog');
        $definitions = json_decode(file_get_contents(dirname(__DIR__, 2).'/config/aspect-catalog.json'), true);
        $definitions['preferences'][0]['values'] = ['1-2'];
        file_put_contents($path, json_encode($definitions));
        static::getContainer()->set(AspectCatalog::class, new AspectCatalog($path, new \Psr\Log\NullLogger()));
        try {
            $part = $this->createPart($client, [['key' => 'way.footwalk.1.width', 'value' => '1-2']]);
            $definitions['types'][0]['attributes'][0]['values'] = ['<1', '>2'];
            file_put_contents($path, json_encode($definitions));
            $client->jsonRequest('POST', '/api/node-groups', ['id' => $part['id'], 'tags' => $part['tags']]);
            self::assertResponseIsSuccessful();
            $client->request('GET', '/api/editing-catalog');
            self::assertSame(['<1', '>2'], json_decode($client->getResponse()->getContent(), true)['aspectTypes'][0]['attributes'][0]['values']);
            $em = static::getContainer()->get('doctrine')->getManager();
            $group = $em->find(NodeGroup::class, $part['id']);
            foreach ($group->getGroupTags() as $entry) $entry->setApproved(true);
            $em->flush();
            self::assertContains($part['id'], array_column(static::getContainer()->get(StreetPartAspects::class)->routingAreas(['aspect:footwalk-wide'])['aspect:footwalk-wide'], 'id'));
            $client->loginUser($this->createUser('structured-retired-other@example.test', ['editor']));
            $client->jsonRequest('POST', '/api/node-groups', ['id' => $part['id'], 'tags' => [['key' => 'way.footwalk.5.width', 'value' => '1-2']]]);
            self::assertResponseStatusCodeSame(422);
            file_put_contents($path, '{');
            $client->request('GET', '/api/editing-catalog');
            self::assertResponseStatusCodeSame(503);
            $client->jsonRequest('POST', '/api/node-groups', ['id' => $part['id'], 'name' => 'must roll back', 'tags' => [['key' => 'way.footwalk.5.width', 'value' => '>2']]]);
            self::assertResponseStatusCodeSame(503);
        } finally {
            unlink($path);
        }
    }

    public function testDemoUsesNormalTagPersistenceAndKeepsRepeatedTypesIndependent(): void
    {
        $client = static::createClient();
        $user = $this->createUser('structured-demo@example.test', ['editor']);
        $application = new \Symfony\Bundle\FrameworkBundle\Console\Application(static::$kernel);
        $tester = new \Symfony\Component\Console\Tester\CommandTester($application->find('app:aspects:load-demo'));
        self::assertSame(2, $tester->execute(['email' => $user->getEmail()]));
        self::assertSame(0, $tester->execute(['--catalog-only' => true, '--demo' => true]), $tester->getDisplay());
        self::assertSame(0, $tester->execute(['email' => $user->getEmail(), '--demo' => true]), $tester->getDisplay());
        $em = static::getContainer()->get('doctrine')->getManager();
        $group = $em->getRepository(NodeGroup::class)->findOneBy(['name' => 'Demo cross-section', 'createdByUser' => $user]);
        self::assertNotNull($group);
        $this->groups[] = $group->getId();
        foreach ($group->getMembers() as $member) $this->nodes[] = $member->getNode()->getId();
        self::assertSame(0, $tester->execute(['email' => $user->getEmail(), '--demo' => true]));
        $client->loginUser($user);
        $client->request('GET', '/api/node-groups');
        $part = array_values(array_filter(json_decode($client->getResponse()->getContent(), true), fn ($g) => $g['id'] === $group->getId()))[0];
        self::assertCount(11, $part['tags']);
        $tags = $part['tags'];
        foreach ($tags as &$tag) if ($tag['key'] === 'way.footwalk.1.width') $tag['value'] = '<1';
        unset($tag);
        $client->jsonRequest('POST', '/api/node-groups', ['id' => $part['id'], 'tags' => $tags]);
        self::assertResponseIsSuccessful();
        $saved = json_decode($client->getResponse()->getContent(), true);
        $byKey = array_column($saved['tags'], 'value', 'key');
        self::assertSame('<1', $byKey['way.footwalk.1.width']);
        self::assertSame('1-2', $byKey['way.footwalk.5.width']);
        self::assertSame('paving_stones', $byKey['way.footwalk.5.surface']);
        self::assertSame('2', $byKey['way.roadway.4.lanes']);
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

    public function testWayValidationUsesTrustedClassificationAndGrandfathersOwnedKeys(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $user = $this->createUser('way-vocabulary-editor@example.test', ['editor']);
        $client->loginUser($user);
        $em = static::getContainer()->get('doctrine')->getManager();
        $temp = sys_get_temp_dir() . '/way-fixture-' . bin2hex(random_bytes(4));
        mkdir($temp . '/osm-data', recursive: true);
        file_put_contents($temp . '/osm-data/flensburg.geojson', json_encode(['features' => [['id' => 'w999999999', 'properties' => ['highway' => 'test_footway']], ['id' => 'w999999998', 'properties' => ['highway' => 'test_residential']]]]));
        $vocabularyFile = $temp . '/vocabulary.json';
        file_put_contents($vocabularyFile, json_encode(['wayVocabularies' => ['test_footway' => [['key' => 'surface', 'values' => ['asphalt', 'uneven']]]]]));
        $vocabulary = new EditingVocabulary($vocabularyFile, new \Psr\Log\NullLogger());
        static::getContainer()->set(WayObservationVocabulary::class, new WayObservationVocabulary($vocabulary, $temp));
        try {
            $payload = ['providerNodeId' => 'w999999999', 'latitude' => 54.79, 'longitude' => 9.43, 'tags' => [['key' => 'surface', 'value' => 'asphalt']]];
            $client->jsonRequest('POST', '/api/nodes', $payload);
            self::assertResponseStatusCodeSame(201);
            $node = json_decode($client->getResponse()->getContent(), true);
            $this->nodes[] = $node['id'];
            $payload['providerNodeId'] = 'w999999999';
            file_put_contents($vocabularyFile, json_encode(['wayVocabularies' => ['test_footway' => [['key' => 'tactile_paving', 'values' => []]]]]));
            $payload['tags'] = [['key' => 'surface', 'value' => 'uneven']];
            $client->jsonRequest('POST', '/api/nodes', $payload);
            self::assertResponseIsSuccessful();
            self::assertSame($payload['tags'], json_decode($client->getResponse()->getContent(), true)['observations']);
            foreach (['w999999998', 'w999999997'] as $unconfigured) {
                try {
                    static::getContainer()->get(WayObservationVocabulary::class)->validate(new \App\Api\SaveUserNodeRequest(providerNodeId: $unconfigured, tags: [['key' => 'surface', 'value' => 'asphalt']]), null, $user);
                    self::fail('Unconfigured ways must reject observations.');
                } catch (\Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException) {
                    self::assertTrue(true);
                }
            }
            $payload['tags'] = [['key' => 'unknown', 'value' => 'yes']];
            $client->jsonRequest('POST', '/api/nodes', $payload);
            self::assertResponseStatusCodeSame(422);
            $payload['providerNodeId'] = 'w999999999';
        } finally {
            unlink($vocabularyFile);
            unlink($temp . '/osm-data/flensburg.geojson');
            rmdir($temp . '/osm-data');
            rmdir($temp);
        }
    }
}
