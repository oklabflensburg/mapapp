<?php

namespace App\Controller;

use App\Api\SaveTagPrioritiesRequest;
use App\Entity\User;
use App\Service\TagPriorityManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Per-user tag priorities set on the profile page.
 */
final class TagPriorityController extends AbstractController
{
    public function __construct(
        private readonly TagPriorityManager $tagPriorityManager,
    ) {
    }

    #[Route('/api/tag-priorities', name: 'app_tag_priorities_show', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED')]
    public function show(): JsonResponse
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        return $this->json(['priorities' => $this->tagPriorityManager->getPriorities($user)]);
    }

    #[Route('/api/tag-priorities', name: 'app_tag_priorities_save', methods: ['PUT'])]
    #[IsGranted('IS_AUTHENTICATED')]
    public function save(#[MapRequestPayload] SaveTagPrioritiesRequest $request): JsonResponse
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        return $this->json(['priorities' => $this->tagPriorityManager->save($user, $request)]);
    }
}
