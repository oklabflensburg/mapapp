<?php

namespace App\Controller;

use App\Api\RouteQuery;
use App\Entity\User;
use App\Repository\NodeGroupRepository;
use App\Repository\NodeRepository;
use App\Service\NoRouteFoundException;
use App\Service\Routing;
use App\Service\RoutingUnavailableException;
use App\Service\TagPriorityManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Proxies walking routes from the GraphHopper routing service.
 */
final class RoutingController extends AbstractController
{
    public function __construct(
        private readonly Routing $routing,
        private readonly TagPriorityManager $tagPriorityManager,
        private readonly NodeRepository $nodeRepository,
        private readonly NodeGroupRepository $nodeGroupRepository,
    ) {
    }

    #[Route('/api/route', name: 'app_route', methods: ['GET'])]
    public function route(#[MapQueryString(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)] RouteQuery $query): JsonResponse
    {
        // The user's tag priorities from the profile page shape the route:
        // avoid levels detour around tagged nodes and street parts carrying
        // the tag (and around OSM ways of the road class the admin mapped
        // the tag to), prefer levels bias routes toward them. Anonymous
        // visitors get the neutral default of the routing profile.
        $user = $this->getUser();
        $tagPriorities = $user instanceof User
            ? $this->tagPriorityManager->getPriorities($user)
            : [];
        $taggedNodes = $this->nodeRepository->findTaggedNodeCoordinates(
            TagPriorityManager::tagsRelevantForRouting($tagPriorities),
        );
        // Street parts carrying a prioritized tag apply to their whole
        // course, not just around their member nodes.
        $groupAreas = $this->nodeGroupRepository->findAreaGeometriesByTagKeys(
            TagPriorityManager::tagsRelevantForRouting($tagPriorities),
        );

        try {
            $route = $this->routing->findShortestFootRoute(
                $query->fromLat,
                $query->fromLon,
                $query->toLat,
                $query->toLon,
                $tagPriorities,
                $taggedNodes,
                $this->tagPriorityManager->osmRoadClassesByTag(),
                $groupAreas,
            );
        } catch (NoRouteFoundException) {
            return $this->json(['error' => 'no_route'], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        } catch (RoutingUnavailableException) {
            return $this->json(['error' => 'routing_unavailable'], JsonResponse::HTTP_SERVICE_UNAVAILABLE);
        }

        return $this->json($route);
    }
}
