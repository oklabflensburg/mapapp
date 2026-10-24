<?php

namespace App\Repository;

use App\Entity\User;
use App\Entity\UserTagPriority;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method UserTagPriority|null find($id, $lockMode = null, $lockVersion = null)
 * @method UserTagPriority|null findOneBy(array $criteria, ?array $orderBy = null)
 * @method list<UserTagPriority> findAll()
 * @method list<UserTagPriority> findBy(array $criteria, ?array $orderBy = null, $limit = null, $offset = null)
 */
final class UserTagPriorityRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserTagPriority::class);
    }

    /**
     * @return list<UserTagPriority>
     */
    public function findAllForUser(User $user): array
    {
        return $this->findBy(['user' => $user]);
    }

    public function findOneForUser(User $user, string $tag): ?UserTagPriority
    {
        return $this->findOneBy(['user' => $user, 'tag' => $tag]);
    }
}
