<?php

namespace App\Controller;

use App\Api\SaveNodeGroupRequest;
use App\Service\NodeGroupManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class NodeGroupController extends AbstractController
{
    public function __construct(
        private readonly NodeGroupManager $nodeGroupManager,
    ) {
    }

    #[Route('/api/node-groups', name: 'app_node_groups_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        return $this->json($this->nodeGroupManager->listNodeGroups());
    }

    #[Route('/api/node-groups', name: 'app_node_groups_save', methods: ['POST'])]
    #[IsGranted('ROLE_EDITOR')]
    public function save(#[MapRequestPayload] SaveNodeGroupRequest $request): JsonResponse
    {
        $result = $this->nodeGroupManager->save($request);

        return $this->json($result->group, $result->created ? JsonResponse::HTTP_CREATED : JsonResponse::HTTP_OK);
    }
}
