<?php

namespace App\Service;

use App\Entity\Report;
use App\Entity\ReportPhoto;
use App\Repository\ReportRepository;
use App\Service\Exception\ExpiredChallengeException;
use App\Service\Exception\ReportNotFoundException;
use App\Service\Exception\ValidationException;
use DateInterval;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
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
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
        private readonly RouterInterface $router,
        private readonly MailerInterface $mailer,
        private readonly string $uploadsDir,
    ) {
    }

    /**
     * @param array{lat: mixed, lng: mixed, rating: mixed, comment: mixed, name?: mixed, email: mixed} $data
     * @param UploadedFile[] $photos
     */
    public function submit(array $data, array $photos): Report
    {
        $errors = [];

        $lat = filter_var($data['lat'] ?? null, FILTER_VALIDATE_FLOAT);
        $lng = filter_var($data['lng'] ?? null, FILTER_VALIDATE_FLOAT);
        $rating = filter_var($data['rating'] ?? null, FILTER_VALIDATE_INT);
        $comment = trim((string) ($data['comment'] ?? ''));
        $name = trim((string) ($data['name'] ?? ''));
        $email = trim((string) ($data['email'] ?? ''));

        if ($lat === false) {
            $errors['lat'] = 'required';
        }
        if ($lng === false) {
            $errors['lng'] = 'required';
        }
        if ($rating === false || $rating < 1 || $rating > 5) {
            $errors['rating'] = 'must be 1-5';
        }
        if (mb_strlen($comment) < 10 || mb_strlen($comment) > 2000) {
            $errors['comment'] = 'must be 10-2000 characters';
        }
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'must be a valid email address';
        }
        if (count($photos) > self::MAX_PHOTOS) {
            $errors['photos'] = 'max ' . self::MAX_PHOTOS . ' photos';
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
            ->setEmail($email)
            ->setStatus(Report::STATUS_PENDING_EMAIL_CONFIRMATION)
            ->setConfirmationToken(bin2hex(random_bytes(32)))
            ->setConfirmationExpiresAt((new DateTimeImmutable())->add(new DateInterval('PT' . self::CONFIRMATION_TTL_HOURS . 'H')));

        $this->em->persist($report);
        $this->em->flush(); // need the generated id for the upload path below

        $this->storePhotos($report, $photos);
        $this->em->flush();

        $confirmUrl = $this->router->generate('api_reports_confirm', ['token' => $report->getConfirmationToken()], UrlGeneratorInterface::ABSOLUTE_URL);
        $this->logger->info('Report submitted, confirmation link generated', [
            'reportId' => $report->getId(),
            'email' => $email,
            'confirmUrl' => $confirmUrl,
        ]);

        try {
            $this->mailer->send((new Email())
                ->from('notifications@velofreundliches-wetzikon.ch')
                ->to($email)
                ->subject('Bitte bestätigen Sie Ihre VeloMelder-Meldung')
                ->text(
                    "Vielen Dank für Ihre Meldung bei VeloMelder.\n\n"
                    . "Damit sie geprüft und veröffentlicht werden kann, bestätigen Sie bitte Ihre E-Mail-Adresse:\n"
                    . "{$confirmUrl}\n\n"
                    . "Der Link ist " . self::CONFIRMATION_TTL_HOURS . " Stunden gültig. Falls Sie diese Meldung nicht "
                    . "abgeschickt haben, können Sie diese E-Mail ignorieren -- ohne Bestätigung wird nichts veröffentlicht.\n\n"
                    . "Velofreundliches Wetzikon"
                ));
        } catch (TransportExceptionInterface $e) {
            $this->logger->warning('Could not send confirmation email', ['exception' => $e->getMessage()]);
        }

        return $report;
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

    /** @param UploadedFile[] $photos */
    private function storePhotos(Report $report, array $photos): void
    {
        if ($photos === []) {
            return;
        }

        $fs = new Filesystem();
        $targetDir = $this->uploadsDir . '/reports/' . $report->getId();
        $fs->mkdir($targetDir);

        foreach (array_values($photos) as $i => $file) {
            $filename = bin2hex(random_bytes(8)) . '.' . strtolower($file->guessExtension() ?: 'jpg');
            $file->move($targetDir, $filename);

            $photo = new ReportPhoto();
            $photo->setUrl('/uploads/reports/' . $report->getId() . '/' . $filename)
                ->setSortOrder($i);
            $report->addPhoto($photo);
            $this->em->persist($photo);
        }
    }
}
