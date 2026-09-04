<?php

namespace App\Controller\Api;

use App\Entity\Report;
use App\Repository\ReportRepository;
use App\Service\Exception\ExpiredChallengeException;
use App\Service\Exception\ReportNotFoundException;
use App\Service\Exception\ValidationException;
use App\Service\ReportPresenter;
use App\Service\ReportSubmissionService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Public, unauthenticated report endpoints -- see api/openapi.yaml "Public — Reports".
 * Replaces the static velo-meldungen.json fetch. Anti-abuse (X-Challenge-Token) is accepted but
 * not verified in this local-dev pass, per the task's explicit scope -- see README.md/DATABASE.md
 * status notes.
 */
class PublicReportsController extends AbstractApiController
{
    public function __construct(
        private readonly ReportRepository $reports,
        private readonly ReportSubmissionService $submissionService,
    ) {
    }

    #[Route('/reports', name: 'api_reports_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $rating = $request->query->has('rating') ? (int) $request->query->get('rating') : null;
        $reports = $this->reports->findPublished($rating);

        return new JsonResponse(array_map(ReportPresenter::toPublicArray(...), $reports));
    }

    #[Route('/reports/{id}', name: 'api_reports_get', methods: ['GET'], requirements: ['id' => 'm-\d+'])]
    public function get(string $id): JsonResponse
    {
        $numericId = ReportPresenter::parseId($id);
        $report = $numericId !== null ? $this->reports->find($numericId) : null;

        if ($report === null || $report->getStatus() !== Report::STATUS_PUBLISHED) {
            return $this->errorResponse('not_found', 'No resource with that id.', 404);
        }

        return new JsonResponse(ReportPresenter::toPublicArray($report));
    }

    #[Route('/reports', name: 'api_reports_submit', methods: ['POST'])]
    public function submit(Request $request): JsonResponse
    {
        if (!$request->headers->has('X-Challenge-Token')) {
            return $this->errorResponse('validation_error', 'X-Challenge-Token header is required.', 400);
        }

        $photos = $request->files->get('photos');
        if ($photos === null) {
            $photos = [];
        } elseif (!is_array($photos)) {
            $photos = [$photos];
        }

        try {
            $report = $this->submissionService->submit($request->request->all(), $photos);
        } catch (ValidationException $e) {
            return $this->errorResponse('validation_error', $e->getMessage(), 400, $e->getErrors());
        }

        return new JsonResponse([
            'id' => ReportPresenter::formatId($report->getId()),
            'status' => $report->getStatus(),
        ], 202);
    }

    #[Route('/reports/confirm/{token}', name: 'api_reports_confirm', methods: ['GET'])]
    public function confirm(string $token): Response
    {
        try {
            $this->submissionService->confirmEmail($token);
        } catch (ReportNotFoundException $e) {
            return $this->errorResponse('not_found', $e->getMessage(), 404);
        } catch (ExpiredChallengeException $e) {
            return $this->errorResponse('expired', $e->getMessage(), 410);
        }

        return new Response('Bestätigt — Ihre Meldung wartet nun auf die Prüfung durch die Redaktion.', 200);
    }
}
