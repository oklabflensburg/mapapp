<?php

namespace App\Api;

use App\Service\TagPriorityManager;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Tag priorities from the profile page, as a tag => level map covering the
 * tags an admin exposed as route preference settings.
 */
final class SaveTagPrioritiesRequest
{
    public function __construct(
        /**
         * @var array<string, string>
         */
        #[Assert\All([
            new Assert\Choice(callback: [TagPriorityManager::class, 'levels']),
        ])]
        public readonly array $priorities = [],
    ) {
    }
}
