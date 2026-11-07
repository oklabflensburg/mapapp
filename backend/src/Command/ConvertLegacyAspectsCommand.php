<?php

namespace App\Command;

use App\Service\LegacyAspectConverter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Lock\LockFactory;

#[AsCommand(name: 'app:aspects:convert-legacy', description: 'Export legacy definitions and convert aspect rows into tags before schema migration.')]
final class ConvertLegacyAspectsCommand extends Command
{
    public function __construct(private readonly LegacyAspectConverter $converter, private readonly LockFactory $locks)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $lock = $this->locks->createLock('aspect-catalog-conversion');
        if (!$lock->acquire()) {
            $io->error('Another aspect conversion is running.');
            return Command::FAILURE;
        }
        try {
            $this->converter->convert();
            $this->converter->convert(verifyOnly: true);
            $io->success('Legacy tags and file-backed definitions verified. Apply doctrine:migrations:migrate next.');
            return Command::SUCCESS;
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());
            return Command::FAILURE;
        } finally {
            $lock->release();
        }
    }
}
