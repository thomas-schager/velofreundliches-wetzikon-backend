<?php

namespace App\Service;

use App\Entity\AdminUser;
use App\Entity\Report;
use App\Repository\RatingRepository;
use App\Repository\ReportRepository;
use App\Service\Exception\ReportNotFoundException;
use App\Service\Exception\ValidationException;
use App\Service\Exception\VersionConflictException;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

/**
 * Admin moderation of reports: list/get/update/publish/decline/delete. Called from both the
 * Twig admin UI (meldung-detail.html's Speichern/Veröffentlichen/Ablehnen buttons) and the
 * PATCH /admin/reports/{id} JSON API -- one code path per action, see
 * docs/api-implementation-strategy.md §3.1 and §3.3 (optimistic locking).
 */
class ReportModerationService
{
    private const ALLOWED_PATCH_STATUSES = [Report::STATUS_PUBLISHED, Report::STATUS_DECLINED];

    public function __construct(
        private readonly ReportRepository $reports,
        private readonly RatingRepository $ratings,
        private readonly EntityManagerInterface $em,
        private readonly MailerInterface $mailer,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{items: Report[], total: int, page: int}
     */
    public function list(?string $status, int $page): array
    {
        $result = $this->reports->findForAdmin($status, $page);

        return ['items' => $result['items'], 'total' => $result['total'], 'page' => $page];
    }

    public function get(int $id): Report
    {
        $report = $this->reports->find($id);
        if ($report === null) {
            throw new ReportNotFoundException('No resource with that id.');
        }

        return $report;
    }

    /**
     * @param array<string, mixed> $patch AdminReportPatch shape: lat, lng, rating, comment, name,
     *                                     anonymous, status, internalNote, deletePhotoIds, address,
     *                                     addressDistanceM -- all optional. address/addressDistanceM
     *                                     are supplied by the client (reverse-geocoded there, same
     *                                     as the public submission flow in
     *                                     velomelder-gelbes-band.html) rather than recomputed here.
     */
    public function update(Report $report, array $patch, int $expectedVersion, AdminUser $moderator): Report
    {
        if ($report->getVersion() !== $expectedVersion) {
            throw new VersionConflictException('Another admin changed this report first. Re-fetch and retry.');
        }

        // Captured before the transaction so the notification below fires only on a genuine
        // transition into "published" -- not on a redundant PATCH that merely repeats a status
        // the report already has (the admin UI itself prevents that via btnPublish's disabled
        // state, but the API has no such guard, and an email every time would be wrong either
        // way).
        $wasPublished = $report->getStatus() === Report::STATUS_PUBLISHED;

        $this->em->wrapInTransaction(function () use ($report, $patch, $moderator) {
            if (array_key_exists('lat', $patch)) {
                $report->setLat((float) $patch['lat']);
            }
            if (array_key_exists('lng', $patch)) {
                $report->setLng((float) $patch['lng']);
            }
            if (array_key_exists('rating', $patch)) {
                $rating = (int) $patch['rating'];
                if ($rating < 1 || $rating > 5) {
                    throw new ValidationException('Request body failed validation.', ['rating' => 'must be 1-5']);
                }
                $report->setRating($rating);
            }
            if (array_key_exists('comment', $patch)) {
                $report->setComment((string) $patch['comment']);
            }
            if (array_key_exists('name', $patch)) {
                $name = trim((string) $patch['name']);
                $report->setName($name !== '' ? $name : null);
            }
            if (array_key_exists('anonymous', $patch)) {
                $report->setAnonymous((bool) $patch['anonymous']);
            }
            if (array_key_exists('address', $patch)) {
                $address = $patch['address'];
                $report->setAddress($address !== null && $address !== '' ? (string) $address : null);
            }
            if (array_key_exists('addressDistanceM', $patch)) {
                $distance = $patch['addressDistanceM'];
                $report->setAddressDistanceM($distance !== null ? (float) $distance : null);
            }
            if (array_key_exists('internalNote', $patch)) {
                $note = trim((string) $patch['internalNote']);
                $report->setInternalNote($note !== '' ? $note : null);
            }

            if (array_key_exists('deletePhotoIds', $patch) && is_array($patch['deletePhotoIds'])) {
                $idsToDelete = array_map('intval', $patch['deletePhotoIds']);
                foreach ($report->getPhotos() as $photo) {
                    if (in_array($photo->getId(), $idsToDelete, true)) {
                        $report->removePhoto($photo);
                    }
                }
            }

            if (array_key_exists('status', $patch)) {
                $status = (string) $patch['status'];
                if (!in_array($status, self::ALLOWED_PATCH_STATUSES, true)) {
                    throw new ValidationException('Request body failed validation.', ['status' => 'must be published or declined']);
                }
                $report->setStatus($status);
                $report->setModeratedBy($moderator);
                $report->setModeratedAt(new DateTimeImmutable());
            }

            $report->touch();
            $this->em->flush();
        });

        // Outside the transaction on purpose -- a notification failure must not roll back a
        // publish that already succeeded. Best-effort, same reasoning as
        // ReportSubmissionService::submit()'s confirmation email: the admin's action (publish)
        // already succeeded in the database: a mail hiccup is logged, not surfaced as a PATCH
        // failure the admin would have to retry.
        if (!$wasPublished && $report->getStatus() === Report::STATUS_PUBLISHED) {
            try {
                $this->sendPublishedNotification($report);
            } catch (TransportExceptionInterface $e) {
                $this->logger->warning('Could not send report-published notification email', ['exception' => $e->getMessage()]);
            }
        }

        return $report;
    }

    private function sendPublishedNotification(Report $report): void
    {
        $this->mailer->send((new TemplatedEmail())
            ->from(new Address('notifications@velofreundliches-wetzikon.ch', 'Velofreundliches Wetzikon'))
            ->to($report->getEmail())
            ->subject('Deine Meldung wurde veröffentlicht')
            ->htmlTemplate('emails/report_published.html.twig')
            ->textTemplate('emails/report_published.txt.twig')
            ->context([
                'meldungId' => ReportPresenter::formatId($report->getId()),
                'name' => $report->getName(),
                'addressPhrase' => ReportPresenter::addressPhraseForEmail($report->getAddress(), $report->getAddressDistanceM()),
                'ratingLabel' => $this->ratings->find($report->getRating())?->getLabel(),
            ]));
    }

    public function delete(Report $report): void
    {
        $this->em->wrapInTransaction(function () use ($report) {
            $this->em->remove($report);
            $this->em->flush();
        });
    }
}
