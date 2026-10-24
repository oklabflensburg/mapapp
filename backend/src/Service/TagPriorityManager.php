<?php

namespace App\Service;

use App\Api\SaveTagPrioritiesRequest;
use App\Entity\User;
use App\Entity\UserTagPriority;
use App\Repository\RoutePreferenceTagRepository;
use App\Repository\UserTagPriorityRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Stores per-user routing priorities for tags on behalf of the profile page.
 * The tags users can prioritize are the ones an admin exposes through the
 * "User settings" admin section (RoutePreferenceTag).
 */
final class TagPriorityManager
{
    public const DEFAULT_LEVEL = 'neutral';

    /**
     * The tag that additionally maps to OSM steps (road_class == STEPS) in
     * Routing::customModelFor(); kept as a constant because it needs special
     * routing treatment wherever it appears.
     */
    public const STAIRS_TAG = 'stairs';

    /**
     * @return list<string>
     */
    public static function levels(): array
    {
        return ['neutral', 'avoid', 'try_to_avoid', 'prefer', 'strongly_prefer'];
    }

    /**
     * The tags whose level produces routing rules, i.e. whose tagged nodes
     * the router must know about. Every non-neutral level counts: avoid
     * levels penalize the tagged nodes' areas, prefer levels penalize
     * everything outside them.
     *
     * @param array<string, string> $tagPriorities
     *
     * @return list<string>
     */
    public static function tagsRelevantForRouting(array $tagPriorities): array
    {
        return array_keys(array_filter(
            $tagPriorities,
            static fn (string $level): bool => $level !== self::DEFAULT_LEVEL,
        ));
    }

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserTagPriorityRepository $userTagPriorityRepository,
        private readonly RoutePreferenceTagRepository $routePreferenceTagRepository,
    ) {
    }

    /**
     * The tag keys an admin exposed as route preference settings.
     *
     * @return list<string>
     */
    public function tags(): array
    {
        return $this->routePreferenceTagRepository->findAllTagKeys();
    }

    /**
     * Returns the user's priorities as a full tag => level map, with the
     * neutral default for tags without a stored preference. Stored
     * preferences for tags that are no longer exposed are ignored (but
     * kept, so they revive if the tag is exposed again).
     *
     * @return array<string, string>
     */
    public function getPriorities(User $user): array
    {
        $priorities = array_fill_keys($this->tags(), self::DEFAULT_LEVEL);
        foreach ($this->userTagPriorityRepository->findAllForUser($user) as $userTagPriority) {
            if (\array_key_exists($userTagPriority->getTag(), $priorities)) {
                $priorities[$userTagPriority->getTag()] = $userTagPriority->getPriority();
            }
        }

        return $priorities;
    }

    /**
     * Upserts (or, for the neutral default, removes) the stored priorities
     * and returns the resulting full map, like getPriorities(). Only the
     * exposed tags are considered; priorities for other tags in the
     * request are ignored.
     *
     * @return array<string, string>
     */
    public function save(User $user, SaveTagPrioritiesRequest $request): array
    {
        $tags = $this->tags();

        $this->entityManager->wrapInTransaction(function () use ($user, $request, $tags): void {
            foreach ($tags as $tag) {
                $level = $request->priorities[$tag] ?? self::DEFAULT_LEVEL;
                $userTagPriority = $this->userTagPriorityRepository->findOneForUser($user, $tag);

                if ($level === self::DEFAULT_LEVEL) {
                    if ($userTagPriority !== null) {
                        $this->entityManager->remove($userTagPriority);
                    }

                    continue;
                }

                if ($userTagPriority === null) {
                    $this->entityManager->persist(new UserTagPriority($user, $tag, $level));
                } else {
                    $userTagPriority->setPriority($level);
                }
            }
        });

        return $this->getPriorities($user);
    }
}
