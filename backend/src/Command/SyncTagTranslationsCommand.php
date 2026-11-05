<?php

namespace App\Command;

use App\Entity\DefaultObservationTag;
use App\Entity\RoutePreferenceTag;
use App\Entity\TagKeyLabel;
use App\Service\TagKeyLabeler;
use App\Service\TagTranslationCatalog;
use App\Service\TagTranslationCatalogEditor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:tags:sync-translations', description: 'Collect OSM and user tag keys/values into the shared XLIFF catalogs.')]
final class SyncTagTranslationsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $manager,
        private readonly TagTranslationCatalog $catalog,
        private readonly TagTranslationCatalogEditor $editor,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('keys', InputArgument::OPTIONAL, 'OSM frequency TSV: count, key')
            ->addOption('pairs', null, InputOption::VALUE_REQUIRED, 'OSM frequency TSV: count, key, value')
            ->addOption('database', null, InputOption::VALUE_NONE, 'Include all stored tags, default choices and route preference keys')
            ->addOption('import-legacy-labels', null, InputOption::VALUE_NONE, 'Copy old database labels to XLIFF, replacing conflicting file targets');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $entries = [];
        $addKey = static function (string $key, string $category) use (&$entries): void {
            $id = 'key:'.$key;
            $entries[$id] = ['source' => TagKeyLabeler::prettify($key), 'note' => 'Tag key: '.$key, 'category' => ($entries[$id]['category'] ?? null) === 'osm' ? 'osm' : $category];
        };
        $addPair = static function (string $key, string $value, string $category) use (&$entries, $addKey): void {
            $addKey($key, $category);
            $id = TagTranslationCatalog::valueId($key, $value);
            $entries[$id] = ['source' => $value, 'note' => '', 'category' => ($entries[$id]['category'] ?? null) === 'osm' ? 'osm' : $category];
        };
        foreach ([$input->getArgument('keys'), $input->getOption('pairs')] as $index => $path) {
            if ($path === null) {
                continue;
            }
            $handle = fopen($path, 'r');
            if ($handle === false) {
                throw new \RuntimeException('Cannot read '.$path);
            }
            try {
                while (($parts = fgetcsv($handle, separator: "\t", escape: '')) !== false) {
                    if (count($parts) !== ($index === 0 ? 2 : 3) || !ctype_digit($parts[0]) || (int) $parts[0] < 1 || $parts[1] === '') {
                        throw new \InvalidArgumentException('Invalid tag TSV row in '.$path);
                    }
                    $index === 0 ? $addKey($parts[1], 'osm') : $addPair($parts[1], $parts[2], 'osm');
                }
            } finally {
                fclose($handle);
            }
        }
        if ($input->getOption('database')) {
            // Stream scalar pairs: imports can contain many distinct tags.
            foreach ($this->manager->createQuery('SELECT t.key, t.value FROM App\\Entity\\Tag t')->toIterable() as $tag) {
                $addPair($tag['key'], $tag['value'], 'custom');
            }
            foreach ($this->manager->getRepository(DefaultObservationTag::class)->findAll() as $tag) {
                $addKey($tag->getTag(), 'custom');
                foreach ($tag->getDefaultValues() as $value) {
                    $addPair($tag->getTag(), $value, 'custom');
                }
            }
            foreach ($this->manager->getRepository(RoutePreferenceTag::class)->findAll() as $tag) {
                $addKey($tag->getTag(), 'custom');
            }
        }
        $legacy = $input->getOption('import-legacy-labels') ? $this->manager->getRepository(TagKeyLabel::class)->findAll() : [];
        if ($entries === [] && $legacy === []) {
            $output->writeln('<error>Supply a keys TSV, --pairs, --database or --import-legacy-labels.</error>');

            return Command::INVALID;
        }
        ksort($entries);
        // Removing translations requires knowing every current unit, so only
        // a sync with all inputs (OSM keys, OSM pairs, database) may prune.
        $complete = $input->getArgument('keys') !== null && $input->getOption('pairs') !== null && $input->getOption('database');
        foreach (TagKeyLabeler::SUPPORTED_LOCALES as $locale) {
            $localized = $entries;
            foreach ($legacy as $label) {
                if ($label->getLocale() === $locale && $label->getLabel() !== '') {
                    $localized['key:'.$label->getKey()] = ['source' => TagKeyLabeler::prettify($label->getKey()), 'note' => 'Imported legacy label', 'target' => $label->getLabel(), 'category' => $localized['key:'.$label->getKey()]['category'] ?? 'custom'];
                }
            }
            $added = $this->editor->merge($this->catalog->path($locale), $locale, $localized, linkReferences: true);
            $removed = $complete ? $this->editor->prune($this->catalog->path($locale), $locale, array_keys($localized)) : 0;
            $output->writeln($locale.': '.$added.' new translation units'.($complete ? ', '.$removed.' stale units removed' : ''));
        }

        return Command::SUCCESS;
    }
}
