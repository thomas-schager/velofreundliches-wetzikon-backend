<?php

namespace App\Controller\Api;

use App\Repository\RatingRepository;
use App\Repository\RouteFeatureRepository;
use App\Repository\RouteTypeRepository;
use App\Service\RouteFeaturePresenter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Public, unauthenticated registry endpoints -- see api/openapi.yaml "Public — Routes".
 * Replaces the static velo-routes.geojson fetch and the hand-duplicated ROUTE_TYPES JS array.
 */
class RegistryController extends AbstractApiController
{
    public function __construct(
        private readonly RatingRepository $ratings,
        private readonly RouteTypeRepository $routeTypes,
        private readonly RouteFeatureRepository $routeFeatures,
    ) {
    }

    #[Route('/ratings', name: 'api_ratings_list', methods: ['GET'])]
    public function ratings(): JsonResponse
    {
        $items = array_map(static fn ($r) => [
            'rating' => $r->getRating(),
            'label' => $r->getLabel(),
            'color' => $r->getColor(),
        ], $this->ratings->findAllOrdered());

        return new JsonResponse($items);
    }

    #[Route('/route-types', name: 'api_route_types_list', methods: ['GET'])]
    public function routeTypes(): JsonResponse
    {
        $items = array_map(static fn ($t) => [
            'key' => $t->getKey(),
            'label' => $t->getLabel(),
            'color' => $t->getColor(),
            'weight' => $t->getWeight(),
            'band' => $t->isBand(),
            'bandStyle' => $t->getBandStyle(),
            'noDirection' => $t->isNoDirection(),
        ], $this->routeTypes->findAllOrdered());

        return new JsonResponse($items);
    }

    #[Route('/routes', name: 'api_routes_list', methods: ['GET'])]
    public function routes(): JsonResponse
    {
        return new JsonResponse(RouteFeaturePresenter::toFeatureCollection($this->routeFeatures->findAll()));
    }
}
