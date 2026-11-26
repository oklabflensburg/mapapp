<?php

namespace App\Controller;

use App\Api\SaveNodeGroupRequest;
use App\Repository\NodeGroupRepository;
use App\Service\NodeGroupManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class NodeGroupController extends AbstractController
{
    public function __construct(
        private readonly NodeGroupManager $nodeGroupManager,
        private readonly NodeGroupRepository $nodeGroupRepository,
    ) {
    }

    #[Route('/api/node-groups', name: 'app_node_groups_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        return $this->json($this->nodeGroupManager->listNodeGroups());
    }

    #[Route('/api/node-groups', name: 'app_node_groups_save', methods: ['POST'])]
    #[IsGranted('ROLE_EDITOR')]
    public function save(#[MapRequestPayload(serializationContext: ['allow_extra_attributes' => false])] SaveNodeGroupRequest $request): JsonResponse
    {
        $result = $this->nodeGroupManager->save($request);

        return $this->json($result->group, $result->created ? JsonResponse::HTTP_CREATED : JsonResponse::HTTP_OK);
    }

    #[Route('/api/node-groups/{id}', name: 'app_node_groups_delete', methods: ['DELETE'])]
    #[IsGranted('ROLE_EDITOR')]
    public function delete(int $id): JsonResponse
    {
        $group = $this->nodeGroupRepository->find($id);
        if ($group === null) {
            throw new NotFoundHttpException('Node relation does not exist.');
        }
        $this->nodeGroupManager->delete($group);

        return new JsonResponse(status: JsonResponse::HTTP_NO_CONTENT);
    }
}
