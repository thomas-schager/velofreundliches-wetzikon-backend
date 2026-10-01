<?php

namespace App\Service;

use App\Entity\Report;
use App\Entity\ReportPhoto;
use App\Repository\RatingRepository;
use App\Repository\ReportRepository;
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
     * @param array{lat: mixed, lng: mixed, rating: mixed, comment: mixed, name?: mixed, email: mixed, address?: mixed, addressDistanceM?: mixed, captchaAnswer?: mixed} $data
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
            ->setEmail($email)
            ->setStatus(Report::STATUS_PENDING_EMAIL_CONFIRMATION)
            ->setConfirmationToken(bin2hex(random_bytes(32)))
            ->setConfirmationExpiresAt((new DateTimeImmutable())->add(new DateInterval('PT' . self::CONFIRMATION_TTL_HOURS . 'H')));

        $this->em->persist($report);
        $this->em->flush(); // need the generated id for the upload path below

        $this->storePhotos($report, $photoBlobs);
        $this->em->flush();

        $confirmUrl = $this->router->generate('api_reports_confirm', ['token' => $report->getConfirmationToken()], UrlGeneratorInterface::ABSOLUTE_URL);
        $this->logger->info('Report submitted, confirmation link generated', [
            'reportId' => $report->getId(),
            'email' => $email,
            'confirmUrl' => $confirmUrl,
        ]);

        try {
            $this->mailer->send((new TemplatedEmail())
                ->from(new Address('notifications@velofreundliches-wetzikon.ch', 'Velofreundliches Wetzikon'))
                ->to($email)
                ->subject('Bitte bestätige deine Meldung bei Velofreundliches Wetzikon')
                ->htmlTemplate('emails/report_confirmation.html.twig')
                ->textTemplate('emails/report_confirmation.txt.twig')
                ->context([
                    'confirmUrl' => $confirmUrl,
                    'ttlHours' => self::CONFIRMATION_TTL_HOURS,
                    'name' => $report->getName(),
                    'addressPhrase' => $this->addressPhraseForEmail($address, $addressDistanceM),
                    'ratingLabel' => $this->ratings->find((int) $rating)?->getLabel(),
                ]));
        } catch (TransportExceptionInterface $e) {
            $this->logger->warning('Could not send confirmation email', ['exception' => $e->getMessage()]);
        }

        return $report;
    }

    /**
     * Same 20m/50m thresholds as the frontend's formatAddressLabel() (velomelder-gelbes-band.html)
     * -- keep both in sync if the thresholds ever change. Returns a ready-to-embed prepositional
     * phrase ("bei X" / "in der Nähe von X"), not a bare label, since the two cases need different
     * connecting words when dropped into "...Meldung {phrase}..." in the e-mail template.
     */
    private function addressPhraseForEmail(?string $address, ?float $distanceM): ?string
    {
        if ($address === null || $distanceM === null) {
            return null;
        }
        if ($distanceM <= 20) {
            return 'bei ' . $address;
        }
        if ($distanceM <= 50) {
            return 'in der Nähe von ' . $address;
        }

        return null;
    }

    public function confirmEmail(string $token): Report
    {
        $report = $this->reports->findOneByConfirmationToken($token);
        if ($report === null) {
            throw new ReportNotFoundException('Unknown or already-used token.');
        }
        if ($report->getConfirmationExpiresAt() !== null && $report->getConfirmationExpiresAt() < new DateTimeImmutable()) {
            throw new ExpiredChallengeException('Token expired.');
        }

        $report->setStatus(Report::STATUS_PENDING_REVIEW)
            ->setEmailConfirmed(true)
            ->setConfirmationToken(null);
        $report->touch();

        $this->em->flush();

        return $report;
    }

    /** @param string[] $photoBlobs WebP-encoded image data, already converted via PhotoConversionService */
    private function storePhotos(Report $report, array $photoBlobs): void
    {
        if ($photoBlobs === []) {
            return;
        }

        $fs = new Filesystem();
        $targetDir = $this->uploadsDir . '/reports/' . $report->getId();
        $fs->mkdir($targetDir);

        foreach (array_values($photoBlobs) as $i => $blob) {
            $filename = bin2hex(random_bytes(8)) . '.webp';
            $fs->dumpFile($targetDir . '/' . $filename, $blob);

            $photo = new ReportPhoto();
            $photo->setUrl('/uploads/reports/' . $report->getId() . '/' . $filename)
                ->setSortOrder($i);
            $report->addPhoto($photo);
            $this->em->persist($photo);
        }
    }
}
