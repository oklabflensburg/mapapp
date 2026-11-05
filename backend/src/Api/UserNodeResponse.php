<?php

namespace App\Api;

final readonly class UserNodeResponse
{
    /**
     * @param list<array{key: string, value: string}> $tags
     * @param list<array{key: string, value: string}> $observations
     * @param list<array{key: string, value: string, translations: array<string, array{keyTranslation?: string, valueTranslation?: string}>}> $observationTranslations
     */
    public function __construct(
        public int $id,
        public string $providerNodeId,
        public float $latitude,
        public float $longitude,
        public array $tags,
        public array $observations,
        public bool $approved,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $updatedAt,
        public string $provider,
        /** @var list<int> Zero or one parent; array shape retained for API compatibility. */
        public array $parentIds,
        /** @var list<int> */
        public array $childIds,
        /** @var list<RelatedNodeResponse> */
        public array $parents,
        /** @var list<RelatedNodeResponse> */
        public array $children,
        /** @var list<array{key: string, value: string, translations: array<string, array{keyTranslation?: string, valueTranslation?: string}>}> */
        public array $observationTranslations = [],
    ) {
    }
}
