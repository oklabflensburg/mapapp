<?php

namespace App\Api;

final readonly class NodeGroupResponse
{
    /**
     * @param array<string, string> $tags
     * @param array<string, string> $observations
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
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $updatedAt,
    ) {
    }
}
