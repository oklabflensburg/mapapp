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

        /** @var array<string, string> */
        #[Assert\All([
            new Assert\NotBlank(),
            new Assert\Length(max: 255),
        ])]
        #[Assert\Callback([self::class, 'validateTagKeys'])]
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

    /** @param mixed $payload */
    public static function validateTagKeys($payload, ExecutionContextInterface $context): void
    {
        if (!\is_array($payload)) {
            return;
        }

        foreach (array_keys($payload) as $key) {
            if (!\is_string($key) || trim($key) === '') {
                $context->addViolation('Tag keys must be non-empty strings.');
                break;
            }

            if (mb_strlen($key) > 255) {
                $context->addViolation('Tag keys must be at most 255 characters long.');
                break;
            }
        }
    }
}
