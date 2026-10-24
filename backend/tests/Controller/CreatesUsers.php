<?php

namespace App\Tests\Controller;

use App\Entity\User;
use App\Entity\UserRole;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Creates persisted users with roles for functional tests.
 */
trait CreatesUsers
{
    private const DEFAULT_PASSWORD = 'correct horse battery staple';

    private function ensureUserRole(string $name): UserRole
    {
        $entityManager = static::getContainer()->get('doctrine')->getManager();
        $role = $entityManager->getRepository(UserRole::class)->findOneBy(['name' => $name]);

        if ($role === null) {
            $role = new UserRole();
            $role->setName($name);
            $entityManager->persist($role);
            $entityManager->flush();
        }

        return $role;
    }

    /**
     * @param list<string> $roleNames
     */
    private function createUser(string $email, array $roleNames, string $password = self::DEFAULT_PASSWORD): User
    {
        $container = static::getContainer();
        $entityManager = $container->get('doctrine')->getManager();

        $user = $entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
        if ($user !== null) {
            return $user;
        }

        $user = new User();
        $user->setName(ucfirst(explode('@', $email)[0]));
        $user->setEmail($email);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, $password));
        foreach ($roleNames as $roleName) {
            $user->addUserRole($this->ensureUserRole($roleName));
        }

        $entityManager->persist($user);
        $entityManager->flush();

        return $user;
    }
}
