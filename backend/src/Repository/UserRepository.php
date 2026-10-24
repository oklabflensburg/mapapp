<?php

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method User|null find($id, $lockMode = null, $lockVersion = null)
 * @method User|null findOneBy(array $criteria, ?array $orderBy = null)
 * @method list<User> findAll()
 * @method list<User> findBy(array $criteria, ?array $orderBy = null, $limit = null, $offset = null)
 */
final class UserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    /**
     * Returns the user with this email, creating and persisting it on first
     * use.
     */
    public function findOrCreate(string $name, string $email): User
    {
        $user = $this->findOneBy(['email' => $email]);

        if ($user === null) {
            $user = new User();
            $user->setName($name);
            $user->setEmail($email);
            $this->getEntityManager()->persist($user);
        }

        return $user;
    }
}
