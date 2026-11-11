<?php

namespace App\Command;

use App\Service\AspectDirectionMigration;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Lock\LockFactory;

#[AsCommand(name: 'app:aspects:migrate-directions', description: 'Rewrite legacy direction-less structured street-part tags into the direction grammar using an explicit position mapping.')]
final class MigrateAspectDirectionsCommand extends Command
{
    public function __construct(private readonly AspectDirectionMigration $migration, private readonly LockFactory $locks)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('mapping-file', InputArgument::REQUIRED, 'JSON file mapping each legacy "<type>.<index>" to its direction, e.g. {"footwalk.1": "west"}');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $path = (string) $input->getArgument('mapping-file');
        if (!is_readable($path)) {
            $io->error('Mapping file is missing or unreadable: '.$path);

            return Command::INVALID;
        }
        try {
            $mapping = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
            if (!\is_array($mapping)) {
                throw new \RuntimeException('Expected a JSON object of "<type>.<index>" => direction entries.');
            }
        } catch (\JsonException $exception) {
            $io->error('Invalid JSON mapping file: '.$exception->getMessage());

            return Command::INVALID;
        }

        $lock = $this->locks->createLock('aspect-direction-migration');
        if (!$lock->acquire()) {
            $io->error('Another direction migration is running.');

            return Command::FAILURE;
        }
        try {
            $this->migration->convert($mapping);
            $io->success('Legacy structured tags rewritten into the direction grammar and verified.');

            return Command::SUCCESS;
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        } finally {
            $lock->release();
        }
    }
}
