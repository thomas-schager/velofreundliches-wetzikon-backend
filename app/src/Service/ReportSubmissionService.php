<?php

namespace App\Service;

use App\Entity\Report;
use App\Entity\ReportPhoto;
use App\Repository\RatingRepository;
use App\Repository\ReportRepository;
use App\Repository\ReportSourceRepository;
use App\Service\Exception\ExpiredChallengeException;
use App\Service\Exception\ReportNotFoundException;
use App\Service\Exception\ValidationException;
use DateInterval;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;

/**
 * Public submission + double-opt-in confirmation for VeloMelder reports. Called from the public
 * API only (POST /reports, GET /reports/confirm/{token}) -- see
 * docs/api-implementation-strategy.md §3.1.
 */
class ReportSubmissionService
{
    private const CONFIRMATION_TTL_HOURS = 48;
    private const MAX_PHOTOS = 5;

    public function __construct(
        private readonly ReportRepository $reports,
        private readonly RatingRepository $ratings,
        private readonly ReportSourceRepository $sources,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
        private readonly RouterInterface $router,
        private readonly MailerInterface $mailer,
        private readonly CaptchaChallengeService $captcha,
        private readonly PhotoConversionService $photoConverter,
        private readonly string $uploadsDir,
    ) {
    }

    /**
     * @param array{lat: mixed, lng: mixed, rating: mixed, comment: mixed, name?: mixed, email: mixed, address?: mixed, addressDistanceM?: mixed, source: mixed, captchaAnswer?: mixed} $data
     * @param UploadedFile[] $photos
     */
    public function submit(array $data, array $photos, ?string $challengeToken): Report
    {
        $errors = [];

        $lat = filter_var($data['lat'] ?? null, FILTER_VALIDATE_FLOAT);
        $lng = filter_var($data['lng'] ?? null, FILTER_VALIDATE_FLOAT);
        $rating = filter_var($data['rating'] ?? null, FILTER_VALIDATE_INT);
        $comment = trim((string) ($data['comment'] ?? ''));
        $name = trim((string) ($data['name'] ?? ''));
        $email = trim((string) ($data['email'] ?? ''));
        // Already resolved client-side (same reverse-geocoding call that drives the "Adresse"
        // preview text) -- not re-validated against a geocoder here, it's personalization-only
        // (e-mail copy), never used for anything security- or business-logic-relevant.
        $address = trim((string) ($data['address'] ?? ''));
        $address = $address !== '' ? mb_substr($address, 0, 255) : null;
        $addressDistanceM = filter_var($data['addressDistanceM'] ?? null, FILTER_VALIDATE_FLOAT);
        $addressDistanceM = $addressDistanceM !== false ? $addressDistanceM : null; // false, not 0.0, if absent/invalid -- keep a real 0m distance intact
        $source = trim((string) ($data['source'] ?? ''));

        if ($lat === false) {
            $errors['lat'] = 'required';
        }
        if ($lng === false) {
            $errors['lng'] = 'required';
        }
        if ($rating === false || $rating < 1 || $rating > 5) {
            $errors['rating'] = 'must be 1-5';
        }
        if (mb_strlen($comment) < 20 || mb_strlen($comment) > 2000) {
            $errors['comment'] = 'must be 20-2000 characters';
        }
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'must be a valid email address';
        }
        if (count($photos) > self::MAX_PHOTOS) {
            $errors['photos'] = 'max ' . self::MAX_PHOTOS . ' photos';
        }
        if (!$this->captcha->verify($challengeToken, $data['captchaAnswer'] ?? null)) {
            $errors['captchaAnswer'] = 'incorrect or expired, request a new challenge via GET /reports/challenge';
        }
        // Required, not inferred -- every public submission form must identify itself (see
        // ReportSource / report_sources), so future maps can't silently fall through unattributed.
        if ($source === '' || $this->sources->find($source) === null) {
            $errors['source'] = 'required, must be a known key in report_sources';
        }

        // Converted here, before the report is ever persisted, so a corrupt/unreadable photo
        // fails validation cleanly instead of leaving behind a Report row with no photos.
        $photoBlobs = [];
        if (count($photos) <= self::MAX_PHOTOS) {
            foreach ($photos as $photo) {
                try {
                    $photoBlobs[] = $this->photoConverter->convertToWebp($photo->getPathname());
                } catch (RuntimeException) {
                    $errors['photos'] = 'one or more photos could not be read as an image';
                }
            }
        }

        if ($errors !== []) {
            throw new ValidationException('Request body failed validation.', $errors);
        }

        $report = new Report();
        $report->setLat((float) $lat)
            ->setLng((float) $lng)
            ->setRating((int) $rating)
            ->setComment($comment)
            ->setName($name !== '' ? $name : null)
            ->setAnonymous($name === '')
            ->setAddress($address)
            ->setAddressDistanceM($addressDistanceM)
            ->setSource($source)
            ->setEmail($email)
            ->setStatus(Report::STATUS_PENDING_EMAIL_CONFIRMATION)
            ->setConfirmationToken(bin2hex(random_bytes(32)))
            ->setConfirmationExpiresAt((new DateTimeImmutable())->add(new DateInterval('PT' . self::CONFIRMATION_TTL_HOURS . 'H')));

        $this->em->persist($report);
        $this->em->flush(); // need the generated id for the upload path below

        $this->storePhotos($report, $photoBlobs);
        $this->em->flush();

        $this->logger->info('Report submitted, confirmation link generated', [
            'reportId' => $report->getId(),
            'email' => $email,
            'confirmUrl' => $this->router->generate('api_reports_confirm', ['token' => $report->getConfirmationToken()], UrlGeneratorInterface::ABSOLUTE_URL),
        ]);

        try {
            $this->sendConfirmationEmail($report);
        } catch (TransportExceptionInterface $e) {
            $this->logger->warning('Could not send confirmation email', ['exception' => $e->getMessage()]);
        }

        return $report;
    }

    /**
     * Admin-triggered resend -- the "Bestätigungs-E-Mail erneut senden" action available on a
     * Meldung that's still awaiting email confirmation (see
     * AdminReportsController::resendConfirmation()). Reuses the existing confirmation token
     * rather than issuing a new one -- resending isn't a new identity check, just a fresh copy
     * of the same link -- and resets confirmationExpiresAt to a full new 48h window from now.
     * Unlike submit()'s best-effort send, a transport failure here is NOT swallowed: sending the
     * email *is* the entire point of this action, so the admin needs to see that it failed
     * rather than believe it went out.
     */
    public function resendConfirmationEmail(Report $report): void
    {
        if ($report->getStatus() !== Report::STATUS_PENDING_EMAIL_CONFIRMATION) {
            throw new ValidationException('Request body failed validation.', ['status' => 'report is not awaiting email confirmation']);
        }

        $report->setConfirmationExpiresAt((new DateTimeImmutable())->add(new DateInterval('PT' . self::CONFIRMATION_TTL_HOURS . 'H')));
        $report->touch();
        $this->em->flush();

        $this->sendConfirmationEmail($report, isReminder: true);
    }

    private function sendConfirmationEmail(Report $report, bool $isReminder = false): void
    {
        $confirmUrl = $this->router->generate('api_reports_confirm', ['token' => $report->getConfirmationToken()], UrlGeneratorInterface::ABSOLUTE_URL);

        $subject = $isReminder
            ? 'Erinnerung: Bitte bestätige deine Meldung bei Velofreundliches Wetzikon'
            : 'Bitte bestätige deine Meldung bei Velofreundliches Wetzikon';

        $this->mailer->send((new TemplatedEmail())
            ->from(new Address('notifications@velofreundliches-wetzikon.ch', 'Velofreundliches Wetzikon'))
            ->to($report->getEmail())
            ->subject($subject)
            ->htmlTemplate('emails/report_confirmation.html.twig')
            ->textTemplate('emails/report_confirmation.txt.twig')
            ->context([
                'confirmUrl' => $confirmUrl,
                'ttlHours' => self::CONFIRMATION_TTL_HOURS,
                'name' => $report->getName(),
                'addressPhrase' => ReportPresenter::addressPhraseForEmail($report->getAddress(), $report->getAddressDistanceM()),
                'ratingLabel' => $this->ratings->find($report->getRating())?->getLabel(),
            ]));
    }

    /**
     * Shared lookup for both checkConfirmationToken() and confirmEmail() -- finds the report by
     * token and enforces the 48h expiry, without mutating anything itself.
     */
    private function resolveConfirmationToken(string $token): Report
    {
        $report = $this->reports->findOneByConfirmationToken($token);
        if ($report === null) {
            throw new ReportNotFoundException('Unknown token.');
        }
        if ($report->getConfirmationExpiresAt() !== null && $report->getConfirmationExpiresAt() < new DateTimeImmutable()) {
            throw new ExpiredChallengeException('Token expired.');
        }

        return $report;
    }

    /**
     * Read-only -- throws exactly like confirmEmail() would, but never confirms anything.
     * PublicReportsController::confirm() (GET, the link actually embedded in the email) uses
     * this to decide what to render, specifically so that GET has no side effect: email
     * "safe link" scanners (Microsoft Defender/Proofpoint/Mimecast etc.) prefetch every link in
     * an incoming mail with a plain GET before the human ever opens it, and a GET that itself
     * confirmed the report would let a scanner do that instead of the real recipient. The real
     * confirmation only happens via that same page's own POST (auto-submitted by JS on load, or
     * by hand via its <noscript> fallback button) -- scanners don't execute JS or submit forms.
     */
    public function checkConfirmationToken(string $token): void
    {
        $this->resolveConfirmationToken($token);
    }

    /**
     * Deliberately idempotent -- clicking the link again (a second device, or just clicking
     * twice) must not show "link ungültig", so the token is never cleared on first use. It
     * stays valid, and re-confirming is a no-op, until confirmationExpiresAt (still the real
     * 48h cutoff -- that part is unchanged). Only the *first* confirmation actually moves the
     * status to pending_review: a later call must not reset a report an admin has since
     * published/declined back to pending_review.
     */
    public function confirmEmail(string $token): Report
    {
        $report = $this->resolveConfirmationToken($token);

        if (!$report->isEmailConfirmed()) {
            $report->setStatus(Report::STATUS_PENDING_REVIEW)
                ->setEmailConfirmed(true);
            $report->touch();

            $this->em->flush();
        }

        return $report;
    }

    /** @param array<array{large: string, display: string}> $photoBlobs already converted via PhotoConversionService */
    private function storePhotos(Report $report, array $photoBlobs): void
    {
        if ($photoBlobs === []) {
            return;
        }

        $fs = new Filesystem();
        $targetDir = $this->uploadsDir . '/reports/' . $report->getId();
        $fs->mkdir($targetDir);

        foreach (array_values($photoBlobs) as $i => $blobs) {
            // Same random id for both files of one photo, distinguished only by suffix -- keeps
            // the pair visibly related on disk instead of two unrelated-looking filenames.
            $baseFilename = bin2hex(random_bytes(8));
            $largeFilename = $baseFilename . '.webp';
            $displayFilename = $baseFilename . '-display.webp';
            $fs->dumpFile($targetDir . '/' . $largeFilename, $blobs['large']);
            $fs->dumpFile($targetDir . '/' . $displayFilename, $blobs['display']);

            $photo = new ReportPhoto();
            $photo->setUrl('/uploads/reports/' . $report->getId() . '/' . $largeFilename)
                ->setDisplayUrl('/uploads/reports/' . $report->getId() . '/' . $displayFilename)
                ->setSortOrder($i);
            $report->addPhoto($photo);
            $this->em->persist($photo);
        }
    }
}
