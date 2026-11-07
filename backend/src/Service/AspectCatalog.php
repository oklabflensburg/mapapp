<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\Yaml\Yaml;

/** File-backed definitions; instances are ordinary relation tags. */
final class AspectCatalog
{
    public function __construct(
        #[Autowire('%kernel.project_dir%/config/aspect-catalog.json')]
        private readonly string $path,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function read(): array
    {
        try {
            if (!is_readable($this->path)) {
                throw new \RuntimeException('File is missing or unreadable.');
            }
            $raw = file_get_contents($this->path);
            Yaml::parse($raw); // Reject duplicate JSON object keys.
            $shape = json_decode($raw, flags: JSON_THROW_ON_ERROR);
            if (!$shape instanceof \stdClass || !is_array($shape->types ?? null) || !is_array($shape->preferences ?? null)) {
                throw new \RuntimeException('Expected catalog lists.');
            }
            foreach ($shape->types as $type) {
                if (!$type instanceof \stdClass || !is_array($type->attributes ?? null)) {
                    throw new \RuntimeException('Expected attribute list.');
                }
                foreach ($type->attributes as $attribute) {
                    if (!$attribute instanceof \stdClass || !is_array($attribute->values ?? null)) {
                        throw new \RuntimeException('Expected attribute values list.');
                    }
                }
            }
            $data = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
            self::validate($data);

            return $data;
        } catch (\Throwable $exception) {
            $this->logger->error('Aspect catalog unavailable: {path}: {reason}', ['path' => $this->path, 'reason' => $exception->getMessage()]);
            throw new ServiceUnavailableHttpException(null, 'editing_vocabulary_unavailable', $exception);
        }
    }

    public static function validate(mixed $data): void
    {
        if (!is_array($data) || !isset($data['types'], $data['preferences']) || !is_array($data['types']) || !array_is_list($data['types']) || !is_array($data['preferences']) || !array_is_list($data['preferences'])) {
            throw new \RuntimeException('Expected types and preferences lists.');
        }
        $types = [];
        foreach ($data['types'] as $type) {
            self::identifier($type['name'] ?? null);
            $name = $type['name'];
            if (isset($types[$name]) || !is_array($type['attributes'] ?? null) || !array_is_list($type['attributes'])) {
                throw new \RuntimeException('Duplicate type or malformed attributes.');
            }
            $types[$name] = [];
            foreach ($type['attributes'] as $attribute) {
                self::identifier($attribute['key'] ?? null);
                $key = $attribute['key'];
                if (isset($types[$name][$key]) || !in_array($attribute['kind'] ?? null, ['range', 'enum'], true)) {
                    throw new \RuntimeException('Duplicate or malformed attribute.');
                }
                self::values($attribute['values'] ?? null);
                $labels = $attribute['labels'] ?? [];
                if (!is_array($labels)) {
                    throw new \RuntimeException('Expected value labels.');
                }
                foreach ($labels as $value => $label) {
                    self::text((string) $value);
                    self::text($label);
                }
                $types[$name][$key] = $attribute;
            }
        }
        $keys = [];
        foreach ($data['preferences'] as $preference) {
            self::text($preference['key'] ?? null);
            self::text($preference['label'] ?? null);
            $key = $preference['key'];
            if (!str_starts_with($key, 'aspect:') || mb_strlen($key) > 50 || isset($keys[$key]) || !isset($types[$preference['type'] ?? ''][$preference['aspect'] ?? ''])) {
                throw new \RuntimeException('Duplicate or invalid preference predicate.');
            }
            self::values($preference['values'] ?? null);
            // Retired values are intentional predicates on existing tags.
            $keys[$key] = true;
        }
    }

    private static function values(mixed $values): void
    {
        if (!is_array($values) || !array_is_list($values) || count(array_unique($values, SORT_REGULAR)) !== count($values)) {
            throw new \RuntimeException('Expected unique value tokens.');
        }
        foreach ($values as $value) {
            self::text($value);
        }
    }

    private static function identifier(mixed $value): void
    {
        self::text($value);
        if (preg_match('/[.\s]/u', $value)) {
            throw new \RuntimeException('Identifiers cannot contain dots or whitespace.');
        }
    }

    private static function text(mixed $value): void
    {
        if (!is_string($value) || trim($value) === '' || mb_strlen($value) > 255) {
            throw new \RuntimeException('Expected nonblank text of at most 255 characters.');
        }
    }
}
