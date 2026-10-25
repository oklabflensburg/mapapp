<?php

namespace App\Service;

use App\Api\NodeGroupResponse;
use App\Api\RelatedNodeResponse;
use App\Api\SaveNodeGroupRequest;
use App\Api\SaveNodeGroupResult;
use App\Entity\Node;
use App\Entity\NodeGroup;
use App\Entity\NodeGroupTag;
use App\Entity\User;
use App\Repository\NodeGroupRepository;
use App\Repository\NodeProviderRepository;
use App\Repository\NodeRepository;
use App\Repository\NodeTypeRepository;
use App\Repository\TagRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Persists street parts: node groups whose ordered members mark the course
 * of a way, e.g. the tagged part of a street. Tags live on the whole part
 * and its area is the corridor around the member line (see StreetPartArea).
 */
final class NodeGroupManager
{
    private const NODE_PROVIDER_OSM = 'OSM';
    private const NODE_TYPE_OSM = 'osm';
    private const DEFAULT_USER_EMAIL = 'anonymous@example.com';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TokenStorageInterface $tokenStorage,
        private readonly NodeRepository $nodeRepository,
        private readonly NodeTypeRepository $nodeTypeRepository,
        private readonly NodeProviderRepository $nodeProviderRepository,
        private readonly NodeGroupRepository $nodeGroupRepository,
        private readonly TagRepository $tagRepository,
        private readonly UserRepository $userRepository,
        private readonly StreetPartArea $streetPartArea,
    ) {
    }

    public function save(SaveNodeGroupRequest $request): SaveNodeGroupResult
    {
        return $this->entityManager->wrapInTransaction(function () use ($request): SaveNodeGroupResult {
            $group = null;
            if ($request->id !== null) {
                $group = $this->nodeGroupRepository->find($request->id);
                if ($group === null) {
                    throw new UnprocessableEntityHttpException('Node relation does not exist.');
                }
            }

            $created = $group === null;
            if ($created) {
                // A street part is the corridor around the line through its
                // members in order; marking the part of a street between two
                // nodes is the smallest useful part.
                if (\count($request->members) < 2) {
                    throw new UnprocessableEntityHttpException('An area needs at least two points.');
                }
                $group = new NodeGroup();
                $group->setCreatedByUser($this->defaultUser());
                $this->entityManager->persist($group);
            }

            // A null name keeps the current one; an empty string clears it.
            if ($request->name !== null) {
                $trimmed = trim($request->name);
                $group->setName($trimmed === '' ? null : $trimmed);
            }

            foreach ($request->memberRequests() as $reference) {
                $node = $this->resolveMember($reference->id, $reference->providerNodeId, $reference->latitude, $reference->longitude);
                $member = $group->addMember($node);
                if ($member !== null) {
                    $this->entityManager->persist($member);
                }
            }

            $this->assignTags($group, $request->tags);
            $group->touch();
            $this->entityManager->flush();

            return new SaveNodeGroupResult($this->toResponse($group), $created);
        });
    }

    /**
     * @return list<NodeGroupResponse>
     */
    public function listNodeGroups(): array
    {
        $user = $this->currentUser();
        $groups = array_filter(
            $this->nodeGroupRepository->findAllOrderedByRecentUpdate(),
            fn (NodeGroup $group): bool => $this->groupIsVisible($group, $user),
        );

        return array_map($this->toResponse(...), array_values($groups));
    }

    /**
     * A relation without a single approved tag is still pending review, so
     * only the user who created it sees it listed — mirroring the node rule.
     */
    private function groupIsVisible(NodeGroup $group, ?User $user): bool
    {
        foreach ($group->getGroupTags() as $groupTag) {
            if ($groupTag->isApproved()) {
                return true;
            }
        }

        return $user !== null && $group->getCreatedByUser() === $user;
    }

    private function resolveMember(?int $id, ?string $providerNodeId, ?float $latitude, ?float $longitude): Node
    {
        if ($id !== null) {
            $node = $this->nodeRepository->find($id);
            if ($node === null) {
                throw new UnprocessableEntityHttpException('Relation member node does not exist.');
            }

            return $node;
        }

        $node = $this->nodeRepository->findOneByProvider(self::NODE_PROVIDER_OSM, $providerNodeId);
        if ($node === null) {
            $node = new Node();
            $node->setNodeType($this->nodeTypeRepository->findOrCreate(self::NODE_TYPE_OSM));
            $node->setNodeProvider($this->nodeProviderRepository->findOrCreate(self::NODE_PROVIDER_OSM));
            $node->setProviderNodeId($providerNodeId);
            $node->setLatitude($latitude);
            $node->setLongitude($longitude);
            $node->setCreatedByUser($this->defaultUser());
            $this->entityManager->persist($node);
        }

        return $node;
    }

    /**
     * Replaces the current user's tag additions on the relation with the
     * request tags, mirroring node tag saves; other users' tags are kept.
     *
     * @param array<string, string> $tags
     */
    private function assignTags(NodeGroup $group, array $tags): void
    {
        $user = $this->defaultUser();

        foreach ($group->getGroupTags() as $groupTag) {
            if ($groupTag->getCreatedByUser() !== $user) {
                continue;
            }
            $tag = $groupTag->getTag();
            if (($tags[$tag->getKey()] ?? null) === $tag->getValue()) {
                unset($tags[$tag->getKey()]);
            } else {
                $group->getGroupTags()->removeElement($groupTag);
                $this->entityManager->remove($groupTag);
            }
        }

        foreach ($tags as $key => $value) {
            $groupTag = $group->addTag($this->tagRepository->findOrCreate($key, $value), $user);
            if ($groupTag !== null) {
                // Admins approve their own additions, but only on this
                // relation: the same value added elsewhere stays unapproved.
                if ($this->currentUserIsAdmin()) {
                    $groupTag->setApproved(true);
                }
                $this->entityManager->persist($groupTag);
            }
        }
    }

    private function toResponse(NodeGroup $group): NodeGroupResponse
    {
        $tags = [];
        $observations = [];
        $user = $this->currentUser();
        foreach ($group->getGroupTags() as $groupTag) {
            if (!$this->tagIsVisible($groupTag, $user)) {
                continue;
            }
            $tag = $groupTag->getTag();
            $tags[$tag->getKey()] = $tag->getValue();
            if (!$groupTag->isApproved()) {
                $observations[$tag->getKey()] = $tag->getValue();
            }
        }

        return new NodeGroupResponse(
            $group->getId() ?? 0,
            $group->getName(),
            $members = array_values(array_map(fn (Node $node): RelatedNodeResponse => $this->relatedNodeResponse($node), $group->getMembers()->map(static fn ($member): Node => $member->getNode())->toArray())),
            $this->streetPartArea->areaGeometry(array_map(
                static fn (RelatedNodeResponse $member): array => ['latitude' => $member->latitude, 'longitude' => $member->longitude],
                $members,
            )),
            $tags,
            $observations,
            $group->getCreatedAt(),
            $group->getUpdatedAt(),
        );
    }

    private function relatedNodeResponse(Node $node): RelatedNodeResponse
    {
        $name = null;
        $tags = [];
        $user = $this->currentUser();
        foreach ($node->getNodeTags() as $nodeTag) {
            if (!$nodeTag->isApproved() && ($user === null || $nodeTag->getCreatedByUser() !== $user)) {
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
            $node->getLatitude(),
            $node->getLongitude(),
        );
    }

    private function defaultUser(): User
    {
        return $this->currentUser() ?? $this->userRepository->findOrCreate('Anonymous', self::DEFAULT_USER_EMAIL);
    }

    private function currentUser(): ?User
    {
        $token = $this->tokenStorage->getToken();
        $user = $token?->getUser();

        return $user instanceof User ? $user : null;
    }

    private function currentUserIsAdmin(): bool
    {
        $user = $this->currentUser();

        return $user !== null && in_array('ROLE_ADMIN', $user->getRoles(), true);
    }

    private function tagIsVisible(NodeGroupTag $groupTag, ?User $user): bool
    {
        if ($groupTag->isApproved()) {
            return true;
        }

        return $user !== null && $groupTag->getCreatedByUser() === $user;
    }
}
