<?php

namespace App\Command;

use App\Api\SaveNodeGroupRequest;
use App\Entity\NodeGroup;
use App\Entity\User;
use App\Service\NodeGroupManager;
use App\Service\AspectCatalog;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

#[AsCommand(name: 'app:aspects:load-demo', description: 'Explicitly load the example catalog and five-aspect street part in dev/test.')]
final class LoadAspectDemoCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NodeGroupManager $groups,
        private readonly AspectCatalog $catalog,
        private readonly TokenStorageInterface $tokens,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
        #[Autowire('%kernel.environment%')] private readonly string $environment,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('email', InputArgument::OPTIONAL, 'Existing editor who owns the demo observations (not needed with --catalog-only)')
            ->addOption('catalog-only', null, InputOption::VALUE_NONE, 'Validate the file-backed catalog without creating a street part')
            ->addOption('demo', null, InputOption::VALUE_NONE, 'Acknowledge that this explicitly adds test/demo data');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if (!in_array($this->environment, ['dev', 'test'], true) || !$input->getOption('demo')) {
            $io->error('Demo loading requires --demo and the dev or test environment.');
            return Command::INVALID;
        }
        $catalogOnly = (bool) $input->getOption('catalog-only');
        $user = $catalogOnly ? null : $this->em->getRepository(User::class)->findOneBy(['email' => $input->getArgument('email')]);
        if (!$catalogOnly && (!$user || !array_intersect(['ROLE_EDITOR', 'ROLE_ADMIN'], $user->getRoles()))) {
            $io->error('Choose an existing editor or admin account.');
            return Command::INVALID;
        }
        $previous = $this->tokens->getToken();
        if ($user !== null) {
            $this->tokens->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
        }
        try {
            $id = $this->em->wrapInTransaction(function () use ($user, $catalogOnly): int {
                $this->catalog->read();
                $fixture = json_decode(file_get_contents($this->projectDir.'/config/examples/street-part.json'), true, flags: JSON_THROW_ON_ERROR);
                if ($catalogOnly) {
                    return 0;
                }
                $existing = $this->em->getRepository(NodeGroup::class)->findOneBy(['name' => $fixture['name'], 'createdByUser' => $user]);
                if ($existing) {
                    // A repeat run preserves edits made to the loaded demo.
                    return $existing->getId();
                }
                $saved = $this->groups->save(new SaveNodeGroupRequest(name: $fixture['name'], members: $fixture['members'], tags: $fixture['tags']));
                return $saved->group->id;
            });
            if ($catalogOnly) {
                $io->success('File-backed catalog is valid. Reload the catalog in the editor to add aspects.');
                return Command::SUCCESS;
            }
            $io->success('Demo street part #'.$id.' ready. Sign in as '.$user->getEmail().' and open /?lat=54.791&lon=9.437&zoom=18; click its line.');
            return Command::SUCCESS;
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());
            return Command::FAILURE;
        } finally {
            $this->tokens->setToken($previous);
        }
    }
}
