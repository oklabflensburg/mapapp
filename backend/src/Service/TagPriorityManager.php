<?php

namespace App\Service;

use App\Api\SaveTagPrioritiesRequest;
use App\Entity\User;
use App\Entity\UserTagPriority;
use App\Repository\UserTagPriorityRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Stores per-user routing priorities for tags on behalf of the profile page.
 */
final class TagPriorityManager
{
    public const DEFAULT_LEVEL = 'neutral';

    /**
     * Tags a user can prioritize; must stay in sync with the frontend's
     * PRIORITIZABLE_TAGS list in src/stores/settings.ts. "stairs" also maps
     * to OSM steps (road_class == STEPS); the others are custom tags that
     * only apply to app nodes tagged with them.
     *
     * @return list<string>
     */
    public static function tags(): array
    {
        return ['stairs', 'toilets'];
    }

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
    ) {
    }

    /**
     * Returns the user's priorities as a full tag => level map, with the
     * neutral default for tags without a stored preference.
     *
     * @return array<string, string>
     */
    public function getPriorities(User $user): array
    {
        $priorities = array_fill_keys(self::tags(), self::DEFAULT_LEVEL);
        foreach ($this->userTagPriorityRepository->findAllForUser($user) as $userTagPriority) {
            $priorities[$userTagPriority->getTag()] = $userTagPriority->getPriority();
        }

        return $priorities;
    }

    /**
     * Upserts (or, for the neutral default, removes) the stored priorities
     * and returns the resulting full map, like getPriorities().
     *
     * @return array<string, string>
     */
    public function save(User $user, SaveTagPrioritiesRequest $request): array
    {
        $this->entityManager->wrapInTransaction(function () use ($user, $request): void {
            foreach (self::tags() as $tag) {
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
