<?php

namespace App\Controller;

use App\Api\SaveUserNodeRequest;
use App\Service\UserNodeManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class NodeController extends AbstractController
{
    public function __construct(
        private readonly UserNodeManager $userNodeManager,
    ) {
    }

    #[Route('/api/nodes', name: 'app_nodes_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        return $this->json($this->userNodeManager->listUserNodes());
    }

    #[Route('/api/nodes', name: 'app_nodes_save', methods: ['POST'])]
    #[IsGranted('ROLE_EDITOR')]
    public function save(#[MapRequestPayload] SaveUserNodeRequest $request): JsonResponse
    {
        $result = $this->userNodeManager->save($request);

        return $this->json($result->node, $result->created ? JsonResponse::HTTP_CREATED : JsonResponse::HTTP_OK);
    }
}
