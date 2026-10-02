<?php

namespace App\Controller\Admin;

use App\Entity\Report;
use App\Repository\RatingRepository;
use App\Repository\ReportRepository;
use App\Repository\ReportSourceRepository;
use App\Service\Exception\ReportNotFoundException;
use App\Service\ReportPresenter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Twig-rendered moderation queue + detail page. The list/detail reads go straight through
 * ReportRepository (read-only, no business rule to share); the Notiz speichern/Veröffentlichen/
 * Ablehnen actions on the detail page submit client-side to the same PATCH /admin/reports/{id}
 * JSON endpoint the API exposes, which is what actually calls ReportModerationService -- see
 * Controller\Api\AdminReportsController.
 */
class MeldungenController extends AbstractController
{
    public function __construct(
        private readonly ReportRepository $reports,
        private readonly RatingRepository $ratings,
        private readonly ReportSourceRepository $sources,
    ) {
    }

    #[Route('/meldungen', name: 'admin_meldungen', methods: ['GET'])]
    public function list(Request $request): Response
    {
        $allowed = [Report::STATUS_PENDING_REVIEW, Report::STATUS_PUBLISHED, Report::STATUS_PENDING_EMAIL_CONFIRMATION, Report::STATUS_DECLINED];
        $status = $request->query->get('status');
        if (!in_array($status, $allowed, true)) {
            // No "Alle" view anymore -- every tab is a single status, so the bare /meldungen
            // link (sidebar nav, no query string) needs its own default rather than showing
            // everything. "Neu" is the first tab and the one actually requiring action.
            $status = Report::STATUS_PENDING_REVIEW;
        }

        $result = $this->reports->findForAdmin($status, 1, 200);

        $ratingLabels = [];
        foreach ($this->ratings->findAllOrdered() as $rating) {
            $ratingLabels[$rating->getRating()] = $rating->getLabel();
        }

        $sourceLabels = [];
        foreach ($this->sources->findAllOrdered() as $source) {
            $sourceLabels[$source->getKey()] = $source->getLabel();
        }

        return $this->render('admin/meldungen.html.twig', [
            'nav_page' => 'meldungen',
            'pending_count' => $this->reports->countByStatus(Report::STATUS_PENDING_REVIEW),
            'items' => $result['items'],
            'total' => $result['total'],
            'current_status' => $status,
            'rating_labels' => $ratingLabels,
            'source_labels' => $sourceLabels,
            'counts' => [
                'pending_review' => $this->reports->countByStatus(Report::STATUS_PENDING_REVIEW),
                'published' => $this->reports->countByStatus(Report::STATUS_PUBLISHED),
                'pending_email_confirmation' => $this->reports->countByStatus(Report::STATUS_PENDING_EMAIL_CONFIRMATION),
                'declined' => $this->reports->countByStatus(Report::STATUS_DECLINED),
            ],
        ]);
    }

    #[Route('/meldungen/{id}', name: 'admin_meldung_detail', methods: ['GET'], requirements: ['id' => 'm-\d+'])]
    public function detail(string $id): Response
    {
        $numericId = ReportPresenter::parseId($id);
        $report = $numericId !== null ? $this->reports->find($numericId) : null;
        if ($report === null) {
            throw $this->createNotFoundException('No resource with that id.');
        }

        $ratingLabels = [];
        $ratingColors = [];
        foreach ($this->ratings->findAllOrdered() as $rating) {
            $ratingLabels[$rating->getRating()] = $rating->getLabel();
            $ratingColors[$rating->getRating()] = $rating->getColor();
        }

        $sourceLabels = [];
        foreach ($this->sources->findAllOrdered() as $source) {
            $sourceLabels[$source->getKey()] = $source->getLabel();
        }

        return $this->render('admin/meldung_detail.html.twig', [
            'nav_page' => 'meldungen',
            'pending_count' => $this->reports->countByStatus(Report::STATUS_PENDING_REVIEW),
            'report' => $report,
            'report_id' => ReportPresenter::formatId($report->getId()),
            'rating_labels' => $ratingLabels,
            'rating_colors' => $ratingColors,
            'source_labels' => $sourceLabels,
        ]);
    }
}
