<?php

namespace App\Service;

use App\Api\RelatedNodeResponse;
use App\Api\SaveUserNodeRequest;
use App\Api\SaveUserNodeResult;
use App\Api\UserNodeResponse;
use App\Entity\Node;
use App\Entity\NodeRelation;
use App\Entity\NodeTag;
use App\Entity\User;
use App\Repository\NodeProviderRepository;
use App\Repository\NodeRepository;
use App\Repository\NodeTypeRepository;
use App\Repository\TagRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

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
        private readonly TokenStorageInterface $tokenStorage,
        private readonly NodeRepository $nodeRepository,
        private readonly NodeTypeRepository $nodeTypeRepository,
        private readonly NodeProviderRepository $nodeProviderRepository,
        private readonly TagRepository $tagRepository,
        private readonly UserRepository $userRepository,
    ) {
    }

    public function save(SaveUserNodeRequest $request): SaveUserNodeResult
    {
        return $this->entityManager->wrapInTransaction(function () use ($request): SaveUserNodeResult {
            $node = $this->nodeRepository->findOneByProvider($request->provider, $request->providerNodeId);
            $parent = null;
            if ($request->parent !== null) {
                $reference = $request->parent;
                if ($reference->id !== null) {
                    $parent = $this->nodeRepository->find($reference->id);
                    if ($parent === null) {
                        throw new UnprocessableEntityHttpException('Parent node does not exist.');
                    }
                } else {
                    if ($request->provider === self::NODE_PROVIDER_OSM && $reference->providerNodeId === $request->providerNodeId) {
                        throw new UnprocessableEntityHttpException('A node cannot be its own parent.');
                    }
                    $parent = $this->nodeRepository->findOneByProvider(self::NODE_PROVIDER_OSM, $reference->providerNodeId);
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
            $node->setNodeType($this->nodeTypeRepository->findOrCreate(self::NODE_TYPE_USER));
            $node->setLatitude($request->latitude);
            $node->setLongitude($request->longitude);
            $this->assignTags($node, $request->tags);
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
        $nodes = $this->nodeRepository->findAllByNodeType(self::NODE_TYPE_USER);

        return array_map($this->toResponse(...), $nodes);
    }

    private function newNode(string $provider, string $providerNodeId, string $type): Node
    {
        $node = new Node();
        $node->setNodeType($this->nodeTypeRepository->findOrCreate($type));
        $node->setNodeProvider($this->nodeProviderRepository->findOrCreate($provider));
        $node->setProviderNodeId($providerNodeId);
        $node->setCreatedByUser($this->defaultUser());
        $this->entityManager->persist($node);

        return $node;
    }

    private function defaultUser(): User
    {
        // Saves require an authenticated editor; only fall back to the
        // anonymous user when no token is present (e.g. data load).
        return $this->currentUser() ?? $this->userRepository->findOrCreate('Anonymous', self::DEFAULT_USER_EMAIL);
    }

    private function currentUser(): ?User
    {
        $token = $this->tokenStorage->getToken();
        $user = $token?->getUser();

        return $user instanceof User ? $user : null;
    }

    /**
     * Tags created by editors or without an authenticated user (e.g. the
     * anonymous fallback) start unapproved; only admins pre-approve their own.
     */
    private function currentUserIsAdmin(): bool
    {
        $user = $this->currentUser();

        return $user !== null && in_array('ROLE_ADMIN', $user->getRoles(), true);
    }

    /**
     * Replaces the current user's tag additions on the node with the request
     * tags. Tags added by other users are preserved, so the same tag can be
     * attributed to multiple users on one node.
     *
     * @param array<string, string> $tags
     */
    private function assignTags(Node $node, array $tags): void
    {
        $user = $this->defaultUser();

        foreach ($node->getNodeTags() as $nodeTag) {
            if ($nodeTag->getCreatedByUser() !== $user) {
                continue;
            }
            $tag = $nodeTag->getTag();
            if (($tags[$tag->getKey()] ?? null) === $tag->getValue()) {
                unset($tags[$tag->getKey()]);
            } else {
                $node->getNodeTags()->removeElement($nodeTag);
                $this->entityManager->remove($nodeTag);
            }
        }

        foreach ($tags as $key => $value) {
            $nodeTag = $node->addTag($this->tagRepository->findOrCreate($key, $value, $this->currentUserIsAdmin()), $user);
            if ($nodeTag !== null) {
                $this->entityManager->persist($nodeTag);
            }
        }
    }

    /**
     * Each user sees approved tags plus their own unapproved ones; tags
     * another user has not yet had approved stay invisible.
     */
    private function toResponse(Node $node): UserNodeResponse
    {
        $tags = [];
        $observations = [];
        $user = $this->currentUser();
        foreach ($node->getNodeTags() as $nodeTag) {
            if (!$this->tagIsVisible($nodeTag, $user)) {
                continue;
            }
            $tag = $nodeTag->getTag();
            $tags[$tag->getKey()] = $tag->getValue();
            if (!$tag->isApproved()) {
                $observations[$tag->getKey()] = $tag->getValue();
            }
        }

        return new UserNodeResponse(
            $node->getId() ?? 0,
            $node->getProviderNodeId(),
            $node->getLatitude(),
            $node->getLongitude(),
            $tags,
            $observations,
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
        $user = $this->currentUser();
        foreach ($node->getNodeTags() as $nodeTag) {
            if (!$this->tagIsVisible($nodeTag, $user)) {
                continue;
            }
            $tag = $nodeTag->getTag();
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

    private function tagIsVisible(NodeTag $nodeTag, ?User $user): bool
    {
        if ($nodeTag->getTag()->isApproved()) {
            return true;
        }

        return $user !== null && $nodeTag->getCreatedByUser() === $user;
    }
}
