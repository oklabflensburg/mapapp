<?php

namespace App\Command;

use App\Entity\User;
use App\Entity\UserRole;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:user:create',
    description: 'Creates a user with a hashed password and roles.',
)]
final class CreateUserCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Email address (used to log in)')
            ->addArgument('name', InputArgument::REQUIRED, 'Display name')
            ->addArgument('password', InputArgument::REQUIRED, 'Plaintext password to hash and store')
            ->addOption('role', 'r', InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED, 'Role to assign (repeatable: "editor" and/or "admin")', ['editor']);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = (string) $input->getArgument('email');
        $name = (string) $input->getArgument('name');
        $password = (string) $input->getArgument('password');
        $roles = (array) $input->getOption('role');

        $repository = $this->entityManager->getRepository(User::class);
        $user = $repository->findOneBy(['email' => $email]);

        if ($user !== null) {
            $io->error(\sprintf('A user with email "%s" already exists.', $email));

            return Command::FAILURE;
        }

        $user = new User();
        $user->setName($name);
        $user->setEmail($email);
        $user->setPassword($this->passwordHasher->hashPassword($user, $password));

        foreach ($roles as $roleName) {
            $role = $this->entityManager->getRepository(UserRole::class)->findOneBy(['name' => $roleName]);

            if ($role === null) {
                $role = new UserRole();
                $role->setName($roleName);
                $this->entityManager->persist($role);
            }

            $user->addUserRole($role);
        }

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $io->success(\sprintf('Created user "%s" with roles: %s', $email, implode(', ', $roles)));

        return Command::SUCCESS;
    }
}
