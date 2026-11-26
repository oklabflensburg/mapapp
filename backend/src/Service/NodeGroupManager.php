<?php

namespace App\Service;

use App\Api\NodeGroupResponse;
use App\Api\RelatedNodeResponse;
use App\Api\SaveNodeGroupRequest;
use App\Api\SaveNodeGroupResult;
use App\Api\SaveUserNodeRequest;
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
 * Persists observation relations: under the observation-relations model
 * every observation is its own node relation carrying exactly one tag and
 * its own member range (see specs/map-editing/observation-relations/
 * spec.md). A street part's corridor is the area around the member line
 * (see StreetPartArea); a single member makes a point observation.
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
        private readonly StreetPartAspects $aspects,
        private readonly WayObservationVocabulary $wayVocabulary,
        private readonly ObservationTranslations $observationTranslations,
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
                // An observation relation is the range of the observation it
                // carries: at least one point, even a single one for point
                // observations (no corridor then).
                if ($request->members === []) {
                    throw new UnprocessableEntityHttpException('An observation needs at least one point.');
                }
                $group = new NodeGroup();
                $group->setCreatedByUser($this->defaultUser());
                $this->entityManager->persist($group);
            } elseif (!$this->currentUserIsAdmin()) {
                // Approval freezes the observation relation for editors;
                // admins may still correct it.
                foreach ($group->getGroupTags() as $entry) {
                    if ($entry->isApproved()) {
                        throw new UnprocessableEntityHttpException('Approved observations are read-only.');
                    }
                }
            }

            // A null name keeps the current one; an empty string clears it.
            if ($request->name !== null) {
                $trimmed = trim($request->name);
                $group->setName($trimmed === '' ? null : $trimmed);
            }

            // The observation's range: supplied members replace the current
            // ones, so an observation can move its extent without touching
            // any other observation. The deletions flush first: the members'
            // unique constraint would otherwise see old and new rows at once.
            if ($request->members !== []) {
                foreach ($group->getMembers()->toArray() as $member) {
                    $group->getMembers()->removeElement($member);
                    $this->entityManager->remove($member);
                }
                if ($group->getMembers()->count() === 0 && $request->id !== null) {
                    $this->entityManager->flush();
                }
                foreach ($request->memberRequests() as $reference) {
                    $node = $this->resolveMember($reference->id, $reference->providerNodeId, $reference->latitude, $reference->longitude);
                    $member = $group->addMember($node);
                    if ($member !== null) {
                        $this->entityManager->persist($member);
                    }
                }
            }

            $this->entityManager->flush();

            if ($request->tags !== []) {
                $entry = $request->tags[0];
                $this->wayVocabulary->validateRelation($group, $entry, $this->defaultUser());
                $this->aspects->validate($request->tags, $group, $this->defaultUser(), $this->currentUserIsAdmin());
                $this->assignTag($group, $entry);
                $this->observationTranslations->saveGroup($request, $group, $this->defaultUser());
            } elseif ($request->translations !== []) {
                throw new UnprocessableEntityHttpException('Translations must belong to a submitted observation.');
            }

            $group->touch();
            $this->entityManager->flush();

            return new SaveNodeGroupResult($this->toResponse($group), $created);
        });
    }

    /**
     * Deletes an observation relation; removing an observation removes its
     * relation. Only its creator (or an admin) may delete it, and only
     * while nothing on it is approved.
     */
    public function delete(NodeGroup $group): void
    {
        $this->entityManager->wrapInTransaction(function () use ($group): void {
            $user = $this->currentUser();
            if (($user === null || $group->getCreatedByUser() !== $user) && !$this->currentUserIsAdmin()) {
                throw new UnprocessableEntityHttpException('Only the creator can delete an observation relation.');
            }
            if (!$this->currentUserIsAdmin()) {
                foreach ($group->getGroupTags() as $entry) {
                    if ($entry->isApproved()) {
                        throw new UnprocessableEntityHttpException('Approved observations cannot be deleted.');
                    }
                }
            }
            $this->entityManager->remove($group);
            $this->entityManager->flush();
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
     * Sets the relation's one observation tag. An exact echo of what the
     * relation (or an approved twin relation with the same member range)
     * already carries creates no new pending observation; anything else
     * replaces the current user's row.
     *
     * @param array{key: string, value: string} $entry
     */
    private function assignTag(NodeGroup $group, array $entry): void
    {
        $user = $this->defaultUser();
        foreach ($group->getGroupTags() as $groupTag) {
            if ($groupTag->getTag()->getKey() === $entry['key'] && $groupTag->getTag()->getValue() === $entry['value']) {
                return; // The relation already publishes exactly this content.
            }
            if ($groupTag->getCreatedByUser() !== $user) {
                continue;
            }
            $group->getGroupTags()->removeElement($groupTag);
            $this->entityManager->remove($groupTag);
        }

        $tag = $this->tagRepository->findOrCreate($entry['key'], $entry['value']);
        $groupTag = $group->addTag($tag, $user);
        if ($groupTag !== null) {
            // Admins approve their own additions, and echoing already
            // approved content on the same range is public, not pending.
            if ($this->currentUserIsAdmin() || $this->approvedEchoOnRange($group, $tag)) {
                $groupTag->setApproved(true);
            }
            $this->entityManager->persist($groupTag);
        }
    }

    /**
     * Whether an approved relation with the same ordered member range
     * already carries exactly this tag: the editor's echo of public content
     * creates no new pending observation.
     */
    private function approvedEchoOnRange(NodeGroup $group, \App\Entity\Tag $tag): bool
    {
        $signature = $this->rangeSignature($group);
        foreach ($this->nodeGroupRepository->findAllOrderedByRecentUpdate() as $other) {
            if ($other === $group || $this->rangeSignature($other) !== $signature) {
                continue;
            }
            foreach ($other->getGroupTags() as $entry) {
                if ($entry->isApproved() && $entry->getTag() === $tag) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * A relation's range identity: the ordered member node identities.
     *
     * @return list<string>
     */
    private function rangeSignature(NodeGroup $group): array
    {
        $signature = [];
        foreach ($group->getMembers() as $member) {
            $node = $member->getNode();
            $signature[] = $node->getId() !== null
                ? 'id:'.$node->getId()
                : $node->getNodeProvider()->getName().':'.$node->getProviderNodeId();
        }

        return $signature;
    }

    private function toResponse(NodeGroup $group): NodeGroupResponse
    {
        $tags = [];
        $observations = [];
        $approved = false;
        $user = $this->currentUser();
        foreach ($group->getGroupTags() as $groupTag) {
            $approved = $approved || $groupTag->isApproved();
            if (!$this->tagIsVisible($groupTag, $user)) {
                continue;
            }
            $tag = $groupTag->getTag();
            $tags[] = ['key' => $tag->getKey(), 'value' => $tag->getValue()];
            if (!$groupTag->isApproved()) {
                $observations[] = ['key' => $tag->getKey(), 'value' => $tag->getValue()];
            }
        }

        $parents = [];
        foreach ($group->getMembers() as $member) {
            $parent = $member->getNode()->getParent();
            if ($parent !== null) {
                $parents[$parent->getId()] = $this->relatedNodeResponse($parent);
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
            $approved,
            $group->getCreatedAt(),
            $group->getUpdatedAt(),
            array_values($parents),
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
            $tags[] = ['key' => $tag->getKey(), 'value' => $tag->getValue()];
            if ($tag->getKey() === 'name' && trim($tag->getValue()) !== '' && $name === null) {
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
