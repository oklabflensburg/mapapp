<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\Yaml\Yaml;

/** One validated source for public choices and way-save validation. */
final class EditingVocabulary
{
    public function __construct(
        #[Autowire('%kernel.project_dir%/config/way-observation-vocabulary.json')]
        private readonly string $path,
        private readonly LoggerInterface $logger
    ) {
    }

    public function read(): array
    {
        try {
            if (!is_readable($this->path)) {
                throw new \RuntimeException('File is missing or unreadable.');
            }
            $raw = file_get_contents($this->path);
            $data = json_decode($raw, false, 512, JSON_THROW_ON_ERROR);
            // Symfony's JSON-compatible inline YAML parser rejects duplicate
            // object keys, which json_decode otherwise silently overwrites.
            Yaml::parse($raw);
            if (!$data instanceof \stdClass || array_keys(get_object_vars($data)) !== ['wayVocabularies'] || !$data->wayVocabularies instanceof \stdClass) {
                throw new \RuntimeException('Expected wayVocabularies object.');
            }
            $result = [];
            foreach (get_object_vars($data->wayVocabularies) as $type => $entries) {
                $this->text($type);
                if (!is_array($entries)) {
                    throw new \RuntimeException('Entries must be a list.');
                }
                $keys = [];
                $result[$type] = [];
                foreach ($entries as $entry) {
                    if (!$entry instanceof \stdClass || count(get_object_vars($entry)) !== 2 || !isset($entry->key, $entry->values) || !is_array($entry->values)) {
                        throw new \RuntimeException('Malformed vocabulary entry.');
                    }
                    $this->text($entry->key);
                    if (in_array($entry->key, $keys, true)) {
                        throw new \RuntimeException('Duplicate vocabulary key.');
                    }
                    $keys[] = $entry->key;
                    foreach ($entry->values as $value) {
                        $this->text($value);
                    }
                    if (count(array_unique($entry->values)) !== count($entry->values)) {
                        throw new \RuntimeException('Duplicate vocabulary value.');
                    }
                    $result[$type][] = ['key' => $entry->key, 'values' => $entry->values];
                }
            }
            return $result;
        } catch (\Throwable $exception) {
            $this->logger->error('Editing vocabulary unavailable: {path}: {reason}', ['path' => $this->path, 'reason' => $exception->getMessage()]);
            throw new ServiceUnavailableHttpException(null, 'editing_vocabulary_unavailable', $exception);
        }
    }

    private function text(mixed $value): void
    {
        if (!is_string($value) || trim($value) === '' || mb_strlen($value) > 255) {
            throw new \RuntimeException('Vocabulary identifiers must be nonblank strings of at most 255 characters.');
        }
    }
}
