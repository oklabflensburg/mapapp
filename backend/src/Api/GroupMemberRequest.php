<?php

namespace App\Api;

/** A relation member: either a saved node ID or an OSM reference. */
final readonly class GroupMemberRequest
{
    public function __construct(
        public ?int $id,
        public ?string $providerNodeId,
        public ?float $latitude,
        public ?float $longitude,
    ) {
    }

    /**
     * @param array{id?: int|string, providerNodeId?: string, latitude?: int|float, longitude?: int|float} $member
     */
    public static function fromArray(array $member): self
    {
        return new self(
            isset($member['id']) ? (int) $member['id'] : null,
            $member['providerNodeId'] ?? null,
            isset($member['latitude']) ? (float) $member['latitude'] : null,
            isset($member['longitude']) ? (float) $member['longitude'] : null,
        );
    }
}
