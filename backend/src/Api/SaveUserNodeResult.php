<?php

namespace App\Api;

final readonly class SaveUserNodeResult
{
    public function __construct(
        public UserNodeResponse $node,
        public bool $created,
    ) {
    }
}
