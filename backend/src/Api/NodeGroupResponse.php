<?php

namespace App\Api;

final readonly class NodeGroupResponse
{
    /**
     * @param list<array{key: string, value: string}> $tags
     * @param list<array{key: string, value: string}> $observations
     * @param list<RelatedNodeResponse> $members
     * @param array{type: string, coordinates: list<list<list<float>>>}|null $area
     *        GeoJSON polygon geometry of the street part's corridor (null
     *        when the group has fewer than two members), see StreetPartArea
     */
    public function __construct(
        public int $id,
        public ?string $name,
        public array $members,
        public ?array $area,
        public array $tags,
        public array $observations,
        /** Whether a relation tag was approved, mirroring node approval. */
        public bool $approved,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $updatedAt,
        /** @var list<RelatedNodeResponse> Distinct parents of the member nodes. */
        public array $parents = [],
    ) {
    }
}
