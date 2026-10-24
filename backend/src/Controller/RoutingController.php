<?php

namespace App\Controller;

use App\Api\RouteQuery;
use App\Service\NoRouteFoundException;
use App\Service\Routing;
use App\Service\RoutingUnavailableException;
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
    ) {
    }

    #[Route('/api/route', name: 'app_route', methods: ['GET'])]
    public function route(#[MapQueryString(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)] RouteQuery $query): JsonResponse
    {
        try {
            $route = $this->routing->findShortestFootRoute(
                $query->fromLat,
                $query->fromLon,
                $query->toLat,
                $query->toLon,
                $query->profile ?? Routing::DEFAULT_PROFILE,
            );
        } catch (NoRouteFoundException) {
            return $this->json(['error' => 'no_route'], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        } catch (RoutingUnavailableException) {
            return $this->json(['error' => 'routing_unavailable'], JsonResponse::HTTP_SERVICE_UNAVAILABLE);
        }

        return $this->json($route);
    }
}
