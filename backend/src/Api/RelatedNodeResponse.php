<?php

namespace App\Api;

final readonly class RelatedNodeResponse
{
    public function __construct(
        public int $id,
        public string $provider,
        public string $providerNodeId,
        public ?string $name,
        /** @var array<string, string> */
        public array $tags,
    ) {
    }
}
