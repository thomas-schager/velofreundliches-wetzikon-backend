<?php

namespace App\Controller\Api;

use App\Entity\AdminUser;
use App\Service\Exception\RouteBackupNotFoundException;
use App\Service\Exception\ValidationException;
use App\Service\RouteEditingService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Admin, session-authenticated route editing -- see api/openapi.yaml "Admin — Routes". Backs the
 * (not yet built, see README.md) route editor UI. Three-step flow, matching the "no auto-save,
 * show changes before saving" requirement:
 *   1. GET /admin/routes           -- load the current network (features carry their db id)
 *   2. POST /admin/routes/diff     -- preview what a proposed edit would change, nothing written
 *   3. PUT /admin/routes           -- actually save (writes a pre-change backup first)
 * Backups are listable/restorable via GET/POST .../backups -- see RouteEditingService.
 */
class AdminRoutesController extends AbstractApiController
{
    public function __construct(private readonly RouteEditingService $routes)
    {
    }

    #[Route('/admin/routes', name: 'admin_api_routes_get', methods: ['GET'])]
    public function get(): JsonResponse
    {
        return new JsonResponse($this->routes->getCurrentFeatureCollection());
    }

    #[Route('/admin/routes/diff', name: 'admin_api_routes_diff', methods: ['POST'])]
    public function diff(Request $request): JsonResponse
    {
        $body = json_decode($request->getContent(), true);
        if (!is_array($body)) {
            return $this->errorResponse('validation_error', 'Request body must be a GeoJSON FeatureCollection.', 400);
        }

        try {
            $summary = $this->routes->previewChanges($body);
        } catch (ValidationException $e) {
            return $this->errorResponse('validation_error', $e->getMessage(), 400, $e->getErrors());
        }

        return new JsonResponse($summary);
    }

    #[Route('/admin/routes', name: 'admin_api_routes_put', methods: ['PUT'])]
    public function put(Request $request, #[CurrentUser] AdminUser $admin): JsonResponse
    {
        $body = json_decode($request->getContent(), true);
        if (!is_array($body)) {
            return $this->errorResponse('validation_error', 'Request body must be a GeoJSON FeatureCollection.', 400);
        }

        try {
            $result = $this->routes->save($body, $admin);
        } catch (ValidationException $e) {
            return $this->errorResponse('validation_error', $e->getMessage(), 400, $e->getErrors());
        }

        return new JsonResponse([
            'type' => $result['features']['type'],
            'features' => $result['features']['features'],
            'changeSummary' => $result['changeSummary'],
            'backupId' => $result['backupId'],
        ]);
    }

    #[Route('/admin/routes/backups', name: 'admin_api_route_backups_list', methods: ['GET'])]
    public function backups(): JsonResponse
    {
        $items = array_map(static fn (array $b) => [
            'id' => $b['id'],
            'createdAt' => $b['createdAt']->format(DATE_ATOM),
            'createdByEmail' => $b['createdByEmail'],
            'addedCount' => $b['addedCount'],
            'removedCount' => $b['removedCount'],
            'modifiedCount' => $b['modifiedCount'],
            'summary' => $b['summary'],
        ], $this->routes->listBackups());

        return new JsonResponse($items);
    }

    #[Route('/admin/routes/backups/{id}/restore', name: 'admin_api_route_backups_restore', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function restore(int $id, #[CurrentUser] AdminUser $admin): JsonResponse
    {
        try {
            $result = $this->routes->restoreBackup($id, $admin);
        } catch (RouteBackupNotFoundException $e) {
            return $this->errorResponse('not_found', $e->getMessage(), 404);
        }

        return new JsonResponse([
            'type' => $result['features']['type'],
            'features' => $result['features']['features'],
            'changeSummary' => $result['changeSummary'],
            'backupId' => $result['backupId'],
        ]);
    }
}
