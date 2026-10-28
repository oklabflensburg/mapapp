<?php

namespace App\Controller;

use App\Repository\DefaultObservationTagRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Serves the tag keys an admin exposed as default observation tags together
 * with the values users can pick for them, so the Vue app can offer them as
 * ready-made suggestions when users add observations. Public like
 * /api/route-preference-tags: the keys are not sensitive, and anonymous
 * visitors see the same panels (read-only).
 */
final class DefaultObservationTagController extends AbstractController
{
    public function __construct(
        private readonly DefaultObservationTagRepository $defaultObservationTags,
    ) {
    }

    #[Route('/api/default-observation-tags', name: 'app_default_observation_tags', methods: ['GET'])]
    public function tags(): JsonResponse
    {
        return $this->json(['tags' => $this->defaultObservationTags->findAllWithValues()]);
    }
}
