<?php

namespace App\Controller;

use App\Service\EditingVocabulary;
use App\Service\StreetPartAspects;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class EditingCatalogController extends AbstractController
{
    #[Route('/api/editing-catalog', methods: ['GET'])]
    public function catalog(StreetPartAspects $aspects, EditingVocabulary $vocabulary): JsonResponse
    {
        $ways = $vocabulary->read();
        return $this->json(['aspectTypes' => $aspects->catalog(), 'wayVocabularies' => (object) $ways]);
    }
}
