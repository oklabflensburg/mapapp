<?php

namespace App\Api;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

final class SaveUserNodeRequest
{
    private const XML_TEXT = '/^[\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]*$/uD';

    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 64)]
        public string $providerNodeId = '',

        #[Assert\Range(min: -90.0, max: 90.0)]
        public float $latitude = 0.0,

        #[Assert\Range(min: -180.0, max: 180.0)]
        public float $longitude = 0.0,

        /**
         * Tag entries as key/value pairs, so one node can carry several tags
         * with the same key but different values.
         *
         * @var list<array{key: string, value: string}>
         */
        #[Assert\Callback([self::class, 'validateTagEntries'])]
        public array $tags = [],

        #[Assert\Choice(choices: ['OSM', 'local'])]
        public string $provider = 'OSM',

        #[Assert\Valid]
        public ?ParentNodeRequest $parent = null,

        #[Assert\Choice(choices: ['en', 'de'])]
        public string $translationLocale = 'en',

        /** @var list<array<string, string|null>> */
        #[Assert\All([new Assert\NotNull(), new Assert\Type('array'), new Assert\Collection(fields: [
            'key' => new Assert\Required([new Assert\Type('string'), new Assert\NotBlank(), new Assert\Length(max: 255), new Assert\Regex(pattern: self::XML_TEXT)]),
            'value' => new Assert\Required([new Assert\Type('string'), new Assert\NotNull(), new Assert\Length(max: 255), new Assert\Regex(pattern: self::XML_TEXT)]),
            'keyTranslation' => new Assert\Optional([new Assert\Type('string'), new Assert\Length(max: 20000), new Assert\Regex(pattern: self::XML_TEXT)]),
            'valueTranslation' => new Assert\Optional([new Assert\Type('string'), new Assert\Length(max: 20000), new Assert\Regex(pattern: self::XML_TEXT)]),
            'previousKeyTranslation' => new Assert\Optional([new Assert\Type('string'), new Assert\Length(max: 20000)]),
            'previousValueTranslation' => new Assert\Optional([new Assert\Type('string'), new Assert\Length(max: 20000)]),
        ])])]
        #[Assert\Count(max: 500)]
        public array $translations = [],
    ) {
    }

    /**
     * @param mixed $payload
     */
    public static function validateTagEntries($payload, ExecutionContextInterface $context): void
    {
        if (!\is_array($payload)) {
            return;
        }

        foreach ($payload as $index => $tag) {
            if (!\is_array($tag)) {
                $context->addViolation('Tags must be objects with a key and a value.');
                break;
            }

            foreach (['key', 'value'] as $field) {
                $value = $tag[$field] ?? null;
                if (!\is_string($value) || trim($value) === '') {
                    $context->addViolation('Tag keys and values must be non-empty strings.');
                    continue 2;
                }
                if (mb_strlen($value) > 255) {
                    $context->addViolation('Tag keys and values must be at most 255 characters long.');
                    continue 2;
                }
            }
        }
    }

    /**
     * Counts the tag entries per "key\0value" pair so duplicate-tolerant
     * matching can consume one count per existing tag. The null byte cannot
     * appear in XML text, so it never shows up in keys or values.
     *
     * @param list<array{key: string, value: string}> $tags
     * @return array<string, int>
     */
    public static function tagEntryCounts(array $tags): array
    {
        $counts = [];
        foreach ($tags as $tag) {
            if (isset($tag['key'], $tag['value'])) {
                $pair = $tag['key']."\0".$tag['value'];
                $counts[$pair] = ($counts[$pair] ?? 0) + 1;
            }
        }

        return $counts;
    }
}
