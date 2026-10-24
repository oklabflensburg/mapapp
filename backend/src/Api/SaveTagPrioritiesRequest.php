<?php

namespace App\Api;

use App\Service\TagPriorityManager;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Tag priorities from the profile page, as a tag => level map covering the
 * prioritable tags (for now only "stairs").
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
