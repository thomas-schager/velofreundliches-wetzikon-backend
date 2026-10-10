<?php

namespace App\Controller\Api;

use App\Entity\Report;
use App\Repository\ReportRepository;
use App\Service\CaptchaChallengeService;
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
 * Replaces the static velo-meldungen.json fetch. Anti-abuse: the X-Challenge-Token header is
 * verified against a server-issued arithmetic challenge (see GET /reports/challenge and
 * CaptchaChallengeService) -- the client cannot supply its own operands/answer and pass.
 */
class PublicReportsController extends AbstractApiController
{
    public function __construct(
        private readonly ReportRepository $reports,
        private readonly ReportSubmissionService $submissionService,
        private readonly CaptchaChallengeService $captcha,
        private readonly string $velomelderBackUrl,
        private readonly string $velomelderBackLabel,
    ) {
    }

    #[Route('/reports/challenge', name: 'api_reports_challenge', methods: ['GET'])]
    public function challenge(Request $request): JsonResponse
    {
        if (!$request->headers->has('X-Challenge-Token')) {
            return $this->errorResponse('validation_error', 'X-Challenge-Token header is required.', 400);
        }

        return new JsonResponse($this->captcha->issue($request->headers->get('X-Challenge-Token')));
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
            $report = $this->submissionService->submit($request->request->all(), $photos, $request->headers->get('X-Challenge-Token'));
        } catch (ValidationException $e) {
            return $this->errorResponse('validation_error', $e->getMessage(), 400, $e->getErrors());
        }

        return new JsonResponse([
            'id' => ReportPresenter::formatId($report->getId()),
            'status' => $report->getStatus(),
        ], 202);
    }

    /**
     * This link is opened directly in a browser from the confirmation email, by a member of the
     * public -- not an API consumer -- so it renders a branded HTML landing page (styled like
     * the VeloMelder tool itself) instead of the raw JSON/plain-text this used to return.
     *
     * Deliberately side-effect-free (read-only check only, see
     * ReportSubmissionService::checkConfirmationToken()) -- the real confirmation happens via
     * confirmSubmit() below, a POST the rendered page fires itself (JS on load, or the
     * <noscript> fallback button), so that a GET by an email "safe link" scanner can no longer
     * confirm the report in the human's place.
     */
    #[Route('/reports/confirm/{token}', name: 'api_reports_confirm', methods: ['GET'])]
    public function confirm(string $token): Response
    {
        $status = 'pending';
        $httpStatus = 200;
        try {
            $this->submissionService->checkConfirmationToken($token);
        } catch (ReportNotFoundException $e) {
            $status = 'not_found';
            $httpStatus = 404;
        } catch (ExpiredChallengeException $e) {
            $status = 'expired';
            $httpStatus = 410;
        }

        return $this->render('reports/confirm.html.twig', [
            'status' => $status,
            'token' => $token,
            'backUrl' => $this->velomelderBackUrl,
            'backLabel' => $this->velomelderBackLabel,
        ], new Response('', $httpStatus));
    }

    /** The actual confirmation -- see confirm()'s docblock for why it's a separate POST. */
    #[Route('/reports/confirm/{token}', name: 'api_reports_confirm_submit', methods: ['POST'])]
    public function confirmSubmit(string $token): Response
    {
        $status = 'confirmed';
        $httpStatus = 200;
        try {
            $this->submissionService->confirmEmail($token);
        } catch (ReportNotFoundException $e) {
            $status = 'not_found';
            $httpStatus = 404;
        } catch (ExpiredChallengeException $e) {
            $status = 'expired';
            $httpStatus = 410;
        }

        return $this->render('reports/confirm.html.twig', [
            'status' => $status,
            'token' => $token,
            'backUrl' => $this->velomelderBackUrl,
            'backLabel' => $this->velomelderBackLabel,
        ], new Response('', $httpStatus));
    }
}
