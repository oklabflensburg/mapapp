<?php

namespace App\Api;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

final readonly class ParentNodeRequest
{
    public function __construct(
        #[Assert\Positive]
        public ?int $id = null,
        #[Assert\Length(min: 1, max: 64)]
        public ?string $providerNodeId = null,
        #[Assert\Range(min: -90, max: 90)]
        public ?float $latitude = null,
        #[Assert\Range(min: -180, max: 180)]
        public ?float $longitude = null,
    ) {
    }

    #[Assert\Callback]
    public function validate(ExecutionContextInterface $context): void
    {
        if ($this->id !== null) {
            if ($this->providerNodeId !== null || $this->latitude !== null || $this->longitude !== null) {
                $context->addViolation('Specify a saved node ID or an OSM reference, not both.');
            }
        } elseif ($this->providerNodeId === null || trim($this->providerNodeId) === '' || $this->latitude === null || $this->longitude === null) {
            $context->addViolation('An OSM parent requires its feature ID and coordinates.');
        }
    }
}
