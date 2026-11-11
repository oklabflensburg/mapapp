<?php

namespace App\Service;

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

/** One-time conversion used by the migration; no legacy runtime dependency. */
final class LegacyAspectConverter
{
    public function __construct(
        private readonly Connection $connection,
        #[Autowire('%kernel.project_dir%/config/aspect-catalog.json')]
        private readonly string $catalogPath,
    ) {
    }

    public static function type(string $type): string
    {
        return $type === 'sidewalk' ? 'footwalk' : $type;
    }

    public static function token(string $value): string
    {
        return match ($value) {
            '< 1 m' => '<1', '1 m – 2 m' => '1-2', '> 2 m', '> 2' => '>2', default => $value,
        };
    }

    public function convert(bool $verifyOnly = false): void
    {
        $tables = $this->connection->createSchemaManager()->listTableNames();
        $legacy = ['aspect_types', 'aspect_attributes', 'aspect_preferences', 'street_part_aspects'];
        $present = array_intersect($legacy, $tables);
        if (!$present) {
            return;
        }
        if (count($present) !== count($legacy)) {
            throw new \RuntimeException('Incomplete legacy aspect schema; restore missing tables before conversion.');
        }
        $previous = file_get_contents($this->catalogPath);
        $catalog = json_decode($previous, true, flags: JSON_THROW_ON_ERROR);
        AspectCatalog::validate($catalog);
        $originalCatalog = $catalog;
        $types = array_column($catalog['types'], null, 'name');
        $preferences = array_column($catalog['preferences'], null, 'key');
        $legacyTypes = [];
        $imported = [];
        $attributes = [];
        foreach ($this->connection->fetchAllAssociative('SELECT * FROM aspect_types ORDER BY id') as $type) {
            $name = self::type($type['name']);
            $legacyTypes[$type['id']] = $name;
            $definition = ['name' => $name, 'attributes' => []];
            foreach ($this->connection->fetchAllAssociative('SELECT * FROM aspect_attributes WHERE aspect_type_id = ? ORDER BY position, id', [$type['id']]) as $attribute) {
                $values = json_decode($attribute['attribute_values'], true, flags: JSON_THROW_ON_ERROR);
                $labels = [];
                foreach ($values as $value) {
                    if (!is_string($value) || isset($labels[self::token($value)])) {
                        throw new \RuntimeException('Invalid or colliding legacy values for '.$name.'.'.$attribute['key']);
                    }
                    $labels[self::token($value)] = $value;
                }
                $entry = ['key' => $attribute['key'], 'kind' => $attribute['kind'], 'values' => array_map(self::token(...), $values), 'labels' => $labels];
                $definition['attributes'][] = $entry;
                $attributes[$attribute['id']] = ['type' => $name, 'aspect' => $attribute['key']];
            }
            // The deployed legacy definitions remain authoritative on conversion.
            if (isset($imported[$name])) {
                throw new \RuntimeException('Legacy type mapping collides at '.$name);
            }
            $imported[$name] = true;
            $types[$name] = $definition;
        }
        foreach ($this->connection->fetchAllAssociative('SELECT * FROM aspect_preferences ORDER BY id') as $preference) {
            $predicate = $attributes[$preference['attribute_id']] ?? throw new \RuntimeException('Unknown legacy preference attribute.');
            $key = 'aspect:'.$preference['id'];
            // The legacy preference model matched any element of the aspect.
            $entry = ['key' => $key, 'label' => $preference['label'], ...$predicate, 'values' => array_map(self::token(...), json_decode($preference['attribute_values'], true, flags: JSON_THROW_ON_ERROR)), 'match' => 'any_element'];
            if (isset($preferences[$key]) && $preferences[$key] !== $entry) {
                throw new \RuntimeException('Legacy preference mapping collides at '.$key);
            }
            $preferences[$key] = $entry;
        }
        // The deployed legacy definitions remain authoritative, so exported
        // file preferences whose aspect the legacy deployment never defined
        // are unrepresentable and leave the export instead of invalidating it.
        $pairs = [];
        foreach ($types as $name => $definition) {
            foreach ($definition['attributes'] as $attribute) {
                $pairs[$name."\0".$attribute['key']] = true;
            }
        }
        $preferences = array_filter($preferences, static fn (array $entry): bool => isset($pairs[$entry['type']."\0".$entry['aspect']]));
        $planned = [];
        foreach ($this->connection->fetchAllAssociative('SELECT * FROM street_part_aspects ORDER BY id') as $row) {
            $type = $legacyTypes[$row['aspect_type_id']] ?? throw new \RuntimeException('Unknown legacy aspect type.');
            foreach (json_decode($row['attribute_values'], true, flags: JSON_THROW_ON_ERROR) as $aspect => $value) {
                $key = 'way.'.$type.'.'.$row['position'].'.'.$aspect;
                $definitionIndex = array_search($aspect, array_column($types[$type]['attributes'], 'key'), true);
                if (!StreetPartAspects::parseLegacy($key) || $definitionIndex === false || !is_string($value)) {
                    throw new \RuntimeException('Cannot map legacy aspect #'.$row['id'].' ('.$key.').');
                }
                $token = self::token($value);
                if (mb_strlen($token) > 255 || trim($token) === '') {
                    throw new \RuntimeException('Invalid legacy value for '.$key);
                }
                $types[$type]['attributes'][$definitionIndex]['labels'][$token] ??= $value;
                $identity = $row['group_id']."\0".$key."\0".$row['created_by_user_id'];
                $entry = ['group_id' => $row['group_id'], 'key' => $key, 'value' => $token, 'user_id' => $row['created_by_user_id'], 'approved' => (bool) $row['approved']];
                if (isset($planned[$identity]) && $planned[$identity] !== $entry) {
                    throw new \RuntimeException('Conflicting legacy rows for '.$key.' on part #'.$row['group_id']);
                }
                $planned[$identity] = $entry;
            }
        }
        // Empty legacy rows cannot be represented without inventing sentinel tags.
        $empty = $this->connection->fetchOne("SELECT id FROM street_part_aspects WHERE attribute_values::jsonb IN ('{}'::jsonb, '[]'::jsonb) LIMIT 1");
        if ($empty !== false) {
            throw new \RuntimeException('Legacy aspect #'.$empty.' has no attributes; remove or fill this empty row before retrying.');
        }
        // Preflight all conflicts before writing either tags or definitions.
        foreach ($planned as $entry) {
            $existing = $this->connection->fetchAllAssociative('SELECT t.value, gt.approved, gt.created_by_user_id FROM node_group_tags gt JOIN tags t ON t.id = gt.tag_id WHERE gt.group_id = ? AND t.key = ?', [$entry['group_id'], $entry['key']]);
            foreach ($existing as $tag) {
                $identity = $entry['group_id']."\0".$entry['key']."\0".$tag['created_by_user_id'];
                if (!isset($planned[$identity]) || $planned[$identity]['value'] !== $tag['value'] || $planned[$identity]['approved'] !== (bool) $tag['approved']) {
                    throw new \RuntimeException('Existing tag collision for '.$entry['key'].' on part #'.$entry['group_id'].'; resolve before retrying.');
                }
            }
        }
        $catalog = ['types' => array_values($types), 'preferences' => array_values($preferences)];
        AspectCatalog::validate($catalog);
        if ($verifyOnly) {
            if ($originalCatalog != $catalog) {
                throw new \RuntimeException('Run app:aspects:convert-legacy before migrating; catalog definitions have not been exported.');
            }
            foreach ($planned as $entry) {
                $count = $this->connection->fetchOne('SELECT COUNT(*) FROM node_group_tags gt JOIN tags t ON t.id = gt.tag_id WHERE gt.group_id = ? AND gt.created_by_user_id = ? AND t.key = ? AND t.value = ? AND gt.approved = ?', [$entry['group_id'], $entry['user_id'], $entry['key'], $entry['value'], $entry['approved']], [\Doctrine\DBAL\ParameterType::INTEGER, \Doctrine\DBAL\ParameterType::INTEGER, \Doctrine\DBAL\ParameterType::STRING, \Doctrine\DBAL\ParameterType::STRING, \Doctrine\DBAL\ParameterType::BOOLEAN]);
                if ((int) $count !== 1) {
                    throw new \RuntimeException('Run app:aspects:convert-legacy before migrating; missing converted tag '.$entry['key']);
                }
            }
            return;
        }
        try {
            $this->connection->transactional(function () use ($planned, $catalog): void {
                foreach ($planned as $entry) {
                    $this->connection->executeStatement('INSERT INTO tags (key, value, translations, updated_at) VALUES (?, ?, ?, CURRENT_TIMESTAMP) ON CONFLICT (key, value) DO NOTHING', [$entry['key'], $entry['value'], '{}']);
                    $tagId = $this->connection->fetchOne('SELECT id FROM tags WHERE key = ? AND value = ?', [$entry['key'], $entry['value']]);
                    $this->connection->executeStatement('INSERT INTO node_group_tags (group_id, tag_id, created_by_user_id, approved) VALUES (?, ?, ?, ?) ON CONFLICT (group_id, tag_id, created_by_user_id) DO NOTHING', [$entry['group_id'], $tagId, $entry['user_id'], $entry['approved']], [\Doctrine\DBAL\ParameterType::INTEGER, \Doctrine\DBAL\ParameterType::INTEGER, \Doctrine\DBAL\ParameterType::INTEGER, \Doctrine\DBAL\ParameterType::BOOLEAN]);
                }
                // Atomic replacement; exported definitions also survive a retried migration.
                (new Filesystem())->dumpFile($this->catalogPath, json_encode($catalog, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
            });
        } catch (\Throwable $exception) {
            (new Filesystem())->dumpFile($this->catalogPath, $previous);
            throw $exception;
        }
    }
}
