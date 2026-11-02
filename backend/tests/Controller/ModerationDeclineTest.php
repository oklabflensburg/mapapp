<?php

namespace App\Tests\Controller;

use App\Entity\Node;
use App\Entity\NodeGroup;
use App\Entity\NodeGroupTag;
use App\Entity\NodeTag;
use App\Entity\Tag;
use App\Entity\User;
use App\Repository\NodeProviderRepository;
use App\Repository\NodeTypeRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ModerationDeclineTest extends WebTestCase
{
    use CreatesUsers;

    private array $objects = [];

    protected function tearDown(): void
    {
        $manager = static::getContainer()->get('doctrine')->getManager();
        foreach ($this->objects as [$class, $id]) {
            $object = $manager->find($class, $id);
            if ($object !== null) {
                $manager->remove($object);
            }
        }
        $manager->flush();
        parent::tearDown();
    }

    private function scope(bool $area, User $author): Node|NodeGroup
    {
        $manager = static::getContainer()->get('doctrine')->getManager();
        if ($area) {
            $scope = new NodeGroup();
            $scope->setName('Decline test '.bin2hex(random_bytes(8)));
        } else {
            $scope = new Node();
            $scope->setNodeType(static::getContainer()->get(NodeTypeRepository::class)->findOrCreate('user'));
            $scope->setNodeProvider(static::getContainer()->get(NodeProviderRepository::class)->findOrCreate('local'));
            $scope->setProviderNodeId('decline-'.bin2hex(random_bytes(8)));
            $scope->setApproved(false);
        }
        $scope->setCreatedByUser($author);
        $manager->persist($scope);
        $manager->flush();
        $this->objects[] = [$scope::class, $scope->getId()];

        return $scope;
    }

    private function tag(): Tag
    {
        $tag = new Tag();
        $tag->setKey('decline_test');
        $tag->setValue(bin2hex(random_bytes(8)));
        $manager = static::getContainer()->get('doctrine')->getManager();
        $manager->persist($tag);
        $manager->flush();
        $this->objects[] = [Tag::class, $tag->getId()];

        return $tag;
    }

    private function observation(Node|NodeGroup $scope, Tag $tag, User $author, bool $approved = false): NodeTag|NodeGroupTag
    {
        $entry = $scope->addTag($tag, $author);
        $entry->setApproved($approved);
        $manager = static::getContainer()->get('doctrine')->getManager();
        $manager->persist($entry);
        $manager->flush();

        return $entry;
    }

    public function testDecliningNodeDeletesItAndItsObservationsAndPreservesChildren(): void
    {
        $client = static::createClient();
        $editor = $this->createUser('decline-editor@example.test', ['editor']);
        $admin = $this->createUser('decline-admin@example.test', ['admin']);
        $node = $this->scope(false, $editor);
        $child = $this->scope(false, $editor);
        $child->setParent($node);
        $tag = $this->tag();
        $entry = $this->observation($node, $tag, $editor);
        $nodeId = $node->getId();
        $entryId = $entry->getId();
        $url = '/admin/node/'.$nodeId.'/decline';

        $client->loginUser($editor, 'admin');
        $client->request('POST', $url);
        self::assertResponseStatusCodeSame(403);
        $client->loginUser($admin, 'admin');
        $client->request('GET', $url);
        self::assertResponseStatusCodeSame(405);
        $client->request('POST', $url, ['_token' => 'invalid']);
        self::assertResponseStatusCodeSame(403);
        $client->request('GET', '/admin/pending-observation/'.$nodeId.'/edit');
        self::assertResponseIsSuccessful();
        $form = $client->getCrawler()->selectButton('Decline node')->form();

        // A stale review page cannot decline an already approved node.
        $manager = static::getContainer()->get('doctrine')->getManager();
        $manager->find(Node::class, $nodeId)->setApproved(true);
        $manager->flush();
        $client->submit($form);
        self::assertResponseStatusCodeSame(409);
        $manager = static::getContainer()->get('doctrine')->getManager();
        $manager->find(Node::class, $nodeId)->setApproved(false);
        $manager->flush();
        $client->submit($form);
        self::assertResponseRedirects('/admin?routeName=app_admin_pending_observations');
        $client->followRedirect();
        self::assertResponseIsSuccessful();
        $manager = static::getContainer()->get('doctrine')->getManager();
        $manager->clear();
        self::assertNull($manager->find(Node::class, $nodeId));
        self::assertNull($manager->find(NodeTag::class, $entryId));
        self::assertNotNull($manager->find(Tag::class, $tag->getId()));
        self::assertNotNull($manager->find(Node::class, $child->getId()));
        self::assertNull($manager->find(Node::class, $child->getId())->getParent());
    }

    public static function tagKinds(): iterable
    {
        yield 'node' => [false];
        yield 'area' => [true];
    }

    #[DataProvider('tagKinds')]
    public function testDecliningTagDeletesOnlyGroupedPendingObservationsHere(bool $area): void
    {
        $client = static::createClient();
        $editor = $this->createUser('decline-editor@example.test', ['editor']);
        $secondEditor = $this->createUser('decline-editor-two@example.test', ['editor']);
        $admin = $this->createUser('decline-admin@example.test', ['admin']);
        $scope = $this->scope($area, $editor);
        $elsewhere = $this->scope($area, $editor);
        $tag = $this->tag();
        $approved = $this->observation($scope, $tag, $admin, true);
        $entry = $this->observation($scope, $tag, $editor);
        $duplicate = $this->observation($scope, $tag, $secondEditor);
        $other = $this->observation($elsewhere, $tag, $editor);
        $different = $this->observation($scope, $this->tag(), $editor);
        $kind = $area ? 'node-group-tag' : 'node-tag';
        $url = '/admin/'.$kind.'/'.$entry->getId().'/decline';
        $screen = $area ? 'pending-relation-observation' : 'pending-observation';
        $reviewUrl = '/admin/'.$screen.'/'.$scope->getId().'/edit';

        $client->loginUser($editor, 'admin');
        $client->request('POST', $url);
        self::assertResponseStatusCodeSame(403);
        $client->loginUser($admin, 'admin');
        $client->request('POST', $url, ['_token' => 'invalid']);
        self::assertResponseStatusCodeSame(403);
        $client->request('GET', $reviewUrl);
        self::assertResponseIsSuccessful();
        // Decline uses its own form, independent of translation draft validation.
        self::assertSelectorExists('button[form="decline-'.str_replace('-', '_', $kind).'-'.$entry->getId().'"]');
        $form = $client->getCrawler()->filter('form[action="'.$url.'"]')->form();
        $client->submit($form, [], ['HTTP_REFERER' => 'http://localhost'.$reviewUrl]);
        self::assertResponseRedirects($reviewUrl);
        $client->followRedirect();
        self::assertResponseIsSuccessful();
        $manager = static::getContainer()->get('doctrine')->getManager();
        $manager->clear();
        self::assertNull($manager->find($entry::class, $entry->getId()));
        self::assertNull($manager->find($duplicate::class, $duplicate->getId()));
        self::assertNotNull($manager->find($approved::class, $approved->getId()));
        self::assertNotNull($manager->find($other::class, $other->getId()));
        self::assertNotNull($manager->find($different::class, $different->getId()));
        self::assertNotNull($manager->find($scope::class, $scope->getId()));
        self::assertNotNull($manager->find(Tag::class, $tag->getId()));

        // A tag approved after rendering also survives a stale Decline action.
        $differentUrl = '/admin/'.$kind.'/'.$different->getId().'/decline';
        $staleForm = $client->getCrawler()->filter('form[action="'.$differentUrl.'"]')->form();
        $manager->find($different::class, $different->getId())->setApproved(true);
        $manager->flush();
        $client->submit($staleForm);
        self::assertResponseStatusCodeSame(409);
        $manager = static::getContainer()->get('doctrine')->getManager();
        self::assertNotNull($manager->find($different::class, $different->getId()));
    }
}
