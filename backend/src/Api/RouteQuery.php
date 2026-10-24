<?php

namespace App\Api;

use App\Service\Routing;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Start and end coordinates of a walking route request. The values are plain
 * query parameters, so MapQueryString binds them from ?fromLat=&fromLon=...
 */
final class RouteQuery
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Range(min: -90, max: 90)]
        public readonly ?float $fromLat = null,
        #[Assert\NotBlank]
        #[Assert\Range(min: -180, max: 180)]
        public readonly ?float $fromLon = null,
        #[Assert\NotBlank]
        #[Assert\Range(min: -90, max: 90)]
        public readonly ?float $toLat = null,
        #[Assert\NotBlank]
        #[Assert\Range(min: -180, max: 180)]
        public readonly ?float $toLon = null,
        // Routing profile configured in backend/routing/graphhopper.yml.
        #[Assert\Choice(callback: [Routing::class, 'profiles'])]
        public readonly ?string $profile = null,
    ) {
    }
}
