<?php

namespace App\Service;

use App\Entity\AdminUser;
use App\Entity\Report;
use App\Repository\ReportRepository;
use App\Service\Exception\ReportNotFoundException;
use App\Service\Exception\ValidationException;
use App\Service\Exception\VersionConflictException;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

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
        private readonly EntityManagerInterface $em,
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
     *                                     anonymous, status, internalNote -- all optional.
     */
    public function update(Report $report, array $patch, int $expectedVersion, AdminUser $moderator): Report
    {
        if ($report->getVersion() !== $expectedVersion) {
            throw new VersionConflictException('Another admin changed this report first. Re-fetch and retry.');
        }

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
            if (array_key_exists('internalNote', $patch)) {
                $note = trim((string) $patch['internalNote']);
                $report->setInternalNote($note !== '' ? $note : null);
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

        return $report;
    }

    public function delete(Report $report): void
    {
        $this->em->wrapInTransaction(function () use ($report) {
            $this->em->remove($report);
            $this->em->flush();
        });
    }
}
