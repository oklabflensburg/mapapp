<?php

namespace App\Api;

final readonly class UserNodeResponse
{
    /**
     * @param array<string, string> $tags
     */
    public function __construct(
        public int $id,
        public string $providerNodeId,
        public float $latitude,
        public float $longitude,
        public array $tags,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $updatedAt,
    ) {
    }
}
