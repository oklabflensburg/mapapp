<?php

namespace App\Service;

use App\Api\RelatedNodeResponse;
use App\Api\SaveUserNodeRequest;
use App\Api\SaveUserNodeResult;
use App\Api\UserNodeResponse;
use App\Entity\Node;
use App\Entity\NodeProvider;
use App\Entity\NodeRelation;
use App\Entity\NodeType;
use App\Entity\Tag;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Persists local nodes, OSM tag edits and their directed parent relations.
 */
final class UserNodeManager
{
    private const NODE_TYPE_USER = 'user';
    private const NODE_PROVIDER_OSM = 'OSM';
    private const DEFAULT_USER_EMAIL = 'anonymous@example.com';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function save(SaveUserNodeRequest $request): SaveUserNodeResult
    {
        return $this->entityManager->wrapInTransaction(function () use ($request): SaveUserNodeResult {
            $node = $this->findNode($request->provider, $request->providerNodeId);
            $parent = null;
            if ($request->parent !== null) {
                $reference = $request->parent;
                if ($reference->id !== null) {
                    $parent = $this->entityManager->find(Node::class, $reference->id);
                    if ($parent === null) {
                        throw new UnprocessableEntityHttpException('Parent node does not exist.');
                    }
                } else {
                    if ($request->provider === self::NODE_PROVIDER_OSM && $reference->providerNodeId === $request->providerNodeId) {
                        throw new UnprocessableEntityHttpException('A node cannot be its own parent.');
                    }
                    $parent = $this->findNode(self::NODE_PROVIDER_OSM, $reference->providerNodeId);
                    if ($parent === null) {
                        $parent = $this->newNode(self::NODE_PROVIDER_OSM, $reference->providerNodeId, 'osm');
                        $parent->setLatitude($reference->latitude);
                        $parent->setLongitude($reference->longitude);
                    }
                }
                if ($parent === $node) {
                    throw new UnprocessableEntityHttpException('A node cannot be its own parent.');
                }
            }

            $created = $node === null;
            $node ??= $this->newNode($request->provider, $request->providerNodeId, self::NODE_TYPE_USER);
            $node->setNodeType($this->nodeType(self::NODE_TYPE_USER));
            $node->setLatitude($request->latitude);
            $node->setLongitude($request->longitude);
            $node->setTags($this->tagsFor($request->tags));
            $node->touch();
            if ($parent !== null) {
                $relation = $node->addParent($parent);
                if ($relation !== null) {
                    $this->entityManager->persist($relation);
                }
            }
            $this->entityManager->flush();

            return new SaveUserNodeResult($this->toResponse($node), $created);
        });
    }

    /**
     * @return list<UserNodeResponse>
     */
    public function listUserNodes(): array
    {
        $repository = $this->entityManager->getRepository(Node::class);

        $nodes = $repository->createQueryBuilder('n')
            ->join('n.nodeType', 't')
            ->where('t.name = :type')
            ->setParameter('type', self::NODE_TYPE_USER)
            ->orderBy('n.updatedAt', 'DESC')
            ->getQuery()
            ->getResult();

        return array_map($this->toResponse(...), $nodes);
    }

    private function newNode(string $provider, string $providerNodeId, string $type): Node
    {
        $node = new Node();
        $node->setNodeType($this->nodeType($type));
        $node->setNodeProvider($this->nodeProvider($provider));
        $node->setProviderNodeId($providerNodeId);
        $node->setCreatedByUser($this->defaultUser());
        $this->entityManager->persist($node);

        return $node;
    }

    private function findNode(string $provider, string $providerNodeId): ?Node
    {
        $repository = $this->entityManager->getRepository(Node::class);

        /** @var Node|null $node */
        $node = $repository->createQueryBuilder('n')
            ->join('n.nodeProvider', 'p')
            ->where('p.name = :provider')
            ->andWhere('n.providerNodeId = :providerNodeId')
            ->setParameter('provider', $provider)
            ->setParameter('providerNodeId', $providerNodeId)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $node;
    }

    private function nodeType(string $name): NodeType
    {
        $type = $this->entityManager->getRepository(NodeType::class)->findOneBy(['name' => $name]);

        if ($type === null) {
            $type = new NodeType();
            $type->setName($name);
            $this->entityManager->persist($type);
        }

        return $type;
    }

    private function nodeProvider(string $name): NodeProvider
    {
        $provider = $this->entityManager->getRepository(NodeProvider::class)->findOneBy(['name' => $name]);

        if ($provider === null) {
            $provider = new NodeProvider();
            $provider->setName($name);
            $this->entityManager->persist($provider);
        }

        return $provider;
    }

    private function defaultUser(): User
    {
        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => self::DEFAULT_USER_EMAIL]);

        if ($user === null) {
            $user = new User();
            $user->setName('Anonymous');
            $user->setEmail(self::DEFAULT_USER_EMAIL);
            $this->entityManager->persist($user);
        }

        return $user;
    }

    /**
     * @param array<string, string> $tags
     *
     * @return list<Tag>
     */
    private function tagsFor(array $tags): array
    {
        $repository = $this->entityManager->getRepository(Tag::class);
        $result = [];

        foreach ($tags as $key => $value) {
            $tag = $repository->findOneBy(['key' => $key, 'value' => $value]);

            if ($tag === null) {
                $tag = new Tag();
                $tag->setKey($key);
                $tag->setValue($value);
                $this->entityManager->persist($tag);
            }

            $result[] = $tag;
        }

        return $result;
    }

    private function toResponse(Node $node): UserNodeResponse
    {
        $tags = [];
        foreach ($node->getTags() as $tag) {
            $tags[$tag->getKey()] = $tag->getValue();
        }

        return new UserNodeResponse(
            $node->getId() ?? 0,
            $node->getProviderNodeId(),
            $node->getLatitude(),
            $node->getLongitude(),
            $tags,
            $node->getCreatedAt(),
            $node->getUpdatedAt(),
            $node->getNodeProvider()->getName(),
            array_values($node->getParentRelations()->map(static fn (NodeRelation $relation): int => $relation->getParent()->getId())->toArray()),
            array_values($node->getChildRelations()->map(static fn (NodeRelation $relation): int => $relation->getChild()->getId())->toArray()),
            array_values($node->getParentRelations()->map(fn (NodeRelation $relation): RelatedNodeResponse => $this->relatedNodeResponse($relation->getParent()))->toArray()),
            array_values($node->getChildRelations()->map(fn (NodeRelation $relation): RelatedNodeResponse => $this->relatedNodeResponse($relation->getChild()))->toArray()),
        );
    }

    private function relatedNodeResponse(Node $node): RelatedNodeResponse
    {
        $name = null;
        $tags = [];
        foreach ($node->getTags() as $tag) {
            $tags[$tag->getKey()] = $tag->getValue();
            if ($tag->getKey() === 'name' && trim($tag->getValue()) !== '') {
                $name = $tag->getValue();
            }
        }

        return new RelatedNodeResponse(
            $node->getId(),
            $node->getNodeProvider()->getName(),
            $node->getProviderNodeId(),
            $name,
            $tags,
        );
    }
}
