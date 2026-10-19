<?php

namespace App\Service;

use App\Api\SaveUserNodeRequest;
use App\Api\SaveUserNodeResult;
use App\Api\UserNodeResponse;
use App\Entity\Node;
use App\Entity\NodeProvider;
use App\Entity\NodeType;
use App\Entity\Tag;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Creates and updates nodes of the "user" type: user-authored copies of
 * provider nodes (currently OSM) carrying custom tags.
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
        $node = $this->findUserNode($request->providerNodeId);

        $created = false;
        if ($node === null) {
            $created = true;
            $node = new Node();
            $node->setNodeType($this->nodeType(self::NODE_TYPE_USER));
            $node->setNodeProvider($this->nodeProvider(self::NODE_PROVIDER_OSM));
            $node->setProviderNodeId($request->providerNodeId);
            $node->setCreatedByUser($this->defaultUser());
            $this->entityManager->persist($node);
        }

        $node->setLatitude($request->latitude);
        $node->setLongitude($request->longitude);
        $node->setTags($this->tagsFor($request->tags));
        $node->touch();
        $this->entityManager->flush();

        return new SaveUserNodeResult($this->toResponse($node), $created);
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

    private function findUserNode(string $providerNodeId): ?Node
    {
        $repository = $this->entityManager->getRepository(Node::class);

        /** @var Node|null $node */
        $node = $repository->createQueryBuilder('n')
            ->join('n.nodeType', 't')
            ->where('t.name = :type')
            ->andWhere('n.providerNodeId = :providerNodeId')
            ->setParameter('type', self::NODE_TYPE_USER)
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
        );
    }
}
