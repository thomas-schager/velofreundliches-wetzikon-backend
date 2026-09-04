<?php

namespace App\Controller\Admin;

use App\Entity\AdminUser;
use App\Entity\Report;
use App\Repository\ReportRepository;
use App\Repository\RouteFeatureRepository;
use DateInterval;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

class DashboardController extends AbstractController
{
    public function __construct(
        private readonly ReportRepository $reports,
        private readonly RouteFeatureRepository $routeFeatures,
    ) {
    }

    #[Route('/dashboard', name: 'admin_dashboard', methods: ['GET'])]
    public function index(#[CurrentUser] AdminUser $user): Response
    {
        $since30d = (new DateTimeImmutable())->sub(new DateInterval('P30D'));

        return $this->render('admin/dashboard.html.twig', [
            'nav_page' => 'dashboard',
            'admin_first_name' => explode(' ', $user->getDisplayName())[0] ?? null,
            'pending_count' => $this->reports->countByStatus(Report::STATUS_PENDING_REVIEW),
            'published_30d' => $this->reports->countByStatusSince(Report::STATUS_PUBLISHED, $since30d),
            'declined_30d' => $this->reports->countByStatusSince(Report::STATUS_DECLINED, $since30d),
            'route_segments_count' => count($this->routeFeatures->findAll()),
            'recent_pending' => $this->reports->findRecentByStatus(Report::STATUS_PENDING_REVIEW, 6),
        ]);
    }
}
