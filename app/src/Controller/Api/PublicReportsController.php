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
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Public, unauthenticated report endpoints -- see api/openapi.yaml "Public — Reports".
 * Replaces the static velo-meldungen.json fetch. Anti-abuse: the X-Challenge-Token header is
 * verified against a server-issued arithmetic challenge (see GET /reports/challenge and
 * CaptchaChallengeService) -- the client cannot supply its own operands/answer and pass.
 *
 * submit() is additionally rate-limited by both IP and the submitted email (see
 * config/packages/rate_limiter.yaml) -- the captcha alone only deters opportunistic bots, not a
 * script willing to solve a single-digit sum, and the real harm (a confirmation email sent to
 * whoever's address was submitted) tracks the target address, not the source IP an attacker could
 * rotate.
 */
class PublicReportsController extends AbstractApiController
{
    public function __construct(
        private readonly ReportRepository $reports,
        private readonly ReportSubmissionService $submissionService,
        private readonly CaptchaChallengeService $captcha,
        private readonly string $velomelderBackUrl,
        private readonly string $velomelderBackLabel,
        #[Autowire(service: 'limiter.report_submit_ip')]
        private readonly RateLimiterFactory $reportSubmitIpLimiter,
        #[Autowire(service: 'limiter.report_submit_email')]
        private readonly RateLimiterFactory $reportSubmitEmailLimiter,
        private readonly LoggerInterface $securityLogger,
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
        // Cheapest possible check, first: reject an over-quota IP before touching the request
        // body, $_FILES, or the captcha cache at all.
        $ipLimit = $this->reportSubmitIpLimiter->create($request->getClientIp())->consume();
        if (!$ipLimit->isAccepted()) {
            $this->securityLogger->warning('Rate limit exceeded on POST /reports', [
                'ip' => $request->getClientIp(),
                'ipLimited' => true,
            ]);

            return $this->rateLimitedResponse($ipLimit);
        }

        // PHP silently empties $_POST/$_FILES, with no per-field error anywhere, when the whole
        // request body exceeds post_max_size -- Content-Length vs. that ini setting is the only
        // remaining signal (see the post_max_size manual page). Caught here, before anything
        // else, so an oversized upload surfaces as a clear error instead of looking like a form
        // with every required field left empty. ReportSubmissionService::MAX_PHOTO_BYTES (and
        // the client-side copy in velomelder-gelbes-band.html) is meant to keep real users well
        // clear of this in practice; this is the backstop for anyone who isn't.
        $postMaxBytes = self::iniToBytes(ini_get('post_max_size'));
        $contentLength = (int) $request->headers->get('Content-Length', '0');
        if ($postMaxBytes > 0 && $contentLength > $postMaxBytes) {
            return $this->errorResponse('validation_error', 'Request body failed validation.', 400, ['photos' => 'combined upload size exceeds the server limit']);
        }

        if (!$request->headers->has('X-Challenge-Token')) {
            return $this->errorResponse('validation_error', 'X-Challenge-Token header is required.', 400);
        }

        // Checked before photo processing (not just before persisting) so an already-throttled
        // caller can't still burn Imagick/disk work on every request -- the target address is
        // the thing actually being protected here (see class docblock), so this is consumed
        // regardless of what the IP limiter above decided.
        $email = mb_strtolower(trim((string) $request->request->get('email', '')));
        $emailLimit = $this->reportSubmitEmailLimiter->create($email)->consume();
        if (!$emailLimit->isAccepted()) {
            $this->securityLogger->warning('Rate limit exceeded on POST /reports', [
                'ip' => $request->getClientIp(),
                'email' => $email,
                'emailLimited' => true,
            ]);

            return $this->rateLimitedResponse($emailLimit);
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
        $newsletterReportId = null;
        $newsletterSig = null;
        try {
            $report = $this->submissionService->confirmEmail($token);
            if (!$this->submissionService->isAlreadySubscribedToNewsletter($report->getEmail())) {
                $newsletterReportId = ReportPresenter::formatId($report->getId());
                $newsletterSig = $this->submissionService->newsletterSignupSignature($report->getId());
            }
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
            'newsletterReportId' => $newsletterReportId,
            'newsletterSig' => $newsletterSig,
        ], new Response('', $httpStatus));
    }

    /**
     * Called by a tiny fetch() when the reporter asks, on the confirm landing page, to be added
     * to the newsletter --
     * see requestNewsletterSignup()'s docblock for why this only sends an internal
     * notification rather than a real subscription. {sig} gates it (not auth, see
     * ReportSubmissionService::newsletterSignupSignature()) so an arbitrary report id can't be
     * used to trigger the notification email for a report that isn't yours.
     */
    #[Route('/reports/{id}/newsletter-signup/{sig}', name: 'api_reports_newsletter_signup', methods: ['POST'], requirements: ['id' => 'm-\d+', 'sig' => '[a-f0-9]{32}'])]
    public function newsletterSignup(string $id, string $sig): JsonResponse
    {
        $numericId = ReportPresenter::parseId($id);
        if ($numericId === null) {
            return $this->errorResponse('not_found', 'No resource with that id.', 404);
        }

        try {
            $this->submissionService->requestNewsletterSignup($numericId, $sig);
        } catch (ReportNotFoundException $e) {
            return $this->errorResponse('not_found', 'No resource with that id.', 404);
        }

        return new JsonResponse(['ok' => true]);
    }

    /** Parses a php.ini shorthand size ("64M", "1G", a bare number of bytes) into bytes. */
    private static function iniToBytes(string|false $value): int
    {
        if ($value === false || trim($value) === '') {
            return 0;
        }
        $value = trim($value);
        $unit = strtolower(substr($value, -1));
        $number = (int) $value;

        return match ($unit) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
