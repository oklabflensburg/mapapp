<?php

namespace App\Api;

final readonly class SaveNodeGroupResult
{
    public function __construct(
        public NodeGroupResponse $group,
        public bool $created,
    ) {
    }
}
