<?php

namespace App\Controller\Api;

use App\Entity\AdminUser;
use App\Service\Exception\ReportNotFoundException;
use App\Service\Exception\ValidationException;
use App\Service\Exception\VersionConflictException;
use App\Service\ReportModerationService;
use App\Service\ReportPresenter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Admin, session-authenticated report moderation -- see api/openapi.yaml "Admin — Reports".
 * Backs the exact same ReportModerationService the Twig admin UI's Speichern/Veröffentlichen/
 * Ablehnen buttons call (see Controller/Admin/MeldungenController) -- one code path per action,
 * docs/api-implementation-strategy.md §3.1.
 */
class AdminReportsController extends AbstractApiController
{
    public function __construct(private readonly ReportModerationService $moderation)
    {
    }

    #[Route('/admin/reports', name: 'admin_api_reports_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $status = $request->query->get('status');
        $page = max(1, (int) $request->query->get('page', 1));

        $result = $this->moderation->list($status, $page);

        return new JsonResponse([
            'items' => array_map(ReportPresenter::toAdminArray(...), $result['items']),
            'total' => $result['total'],
            'page' => $result['page'],
        ]);
    }

    #[Route('/admin/reports/{id}', name: 'admin_api_reports_get', methods: ['GET'], requirements: ['id' => 'm-\d+'])]
    public function get(string $id): JsonResponse
    {
        $numericId = ReportPresenter::parseId($id);
        if ($numericId === null) {
            return $this->errorResponse('not_found', 'No resource with that id.', 404);
        }

        try {
            $report = $this->moderation->get($numericId);
        } catch (ReportNotFoundException $e) {
            return $this->errorResponse('not_found', $e->getMessage(), 404);
        }

        return new JsonResponse(ReportPresenter::toAdminArray($report));
    }

    #[Route('/admin/reports/{id}', name: 'admin_api_reports_patch', methods: ['PATCH'], requirements: ['id' => 'm-\d+'])]
    public function patch(string $id, Request $request, #[CurrentUser] AdminUser $moderator): JsonResponse
    {
        $numericId = ReportPresenter::parseId($id);
        if ($numericId === null) {
            return $this->errorResponse('not_found', 'No resource with that id.', 404);
        }

        $ifMatch = $request->headers->get('If-Match');
        if ($ifMatch === null || !ctype_digit($ifMatch)) {
            return $this->errorResponse('validation_error', 'If-Match header (current version) is required.', 400);
        }

        try {
            $report = $this->moderation->get($numericId);
        } catch (ReportNotFoundException $e) {
            return $this->errorResponse('not_found', $e->getMessage(), 404);
        }

        $patch = json_decode($request->getContent(), true) ?? [];

        try {
            $report = $this->moderation->update($report, $patch, (int) $ifMatch, $moderator);
        } catch (VersionConflictException $e) {
            return $this->errorResponse('version_conflict', $e->getMessage(), 409);
        } catch (ValidationException $e) {
            return $this->errorResponse('validation_error', $e->getMessage(), 400, $e->getErrors());
        }

        return new JsonResponse(ReportPresenter::toAdminArray($report));
    }

    #[Route('/admin/reports/{id}', name: 'admin_api_reports_delete', methods: ['DELETE'], requirements: ['id' => 'm-\d+'])]
    public function delete(string $id): Response
    {
        $numericId = ReportPresenter::parseId($id);
        if ($numericId === null) {
            return $this->errorResponse('not_found', 'No resource with that id.', 404);
        }

        try {
            $report = $this->moderation->get($numericId);
        } catch (ReportNotFoundException $e) {
            return $this->errorResponse('not_found', $e->getMessage(), 404);
        }

        $this->moderation->delete($report);

        return new Response(null, 204);
    }
}
