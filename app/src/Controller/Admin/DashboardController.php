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
            'admin_display_name' => $user->getDisplayName(),
            'admin_first_name' => explode(' ', $user->getDisplayName())[0] ?? null,
            'admin_initials' => $this->initials($user->getDisplayName()),
            'pending_count' => $this->reports->countByStatus(Report::STATUS_PENDING_REVIEW),
            'published_30d' => $this->reports->countByStatusSince(Report::STATUS_PUBLISHED, $since30d),
            'declined_30d' => $this->reports->countByStatusSince(Report::STATUS_DECLINED, $since30d),
            'route_segments_count' => count($this->routeFeatures->findAll()),
            'recent_pending' => $this->reports->findRecentByStatus(Report::STATUS_PENDING_REVIEW, 6),
        ]);
    }

    public static function initials(string $displayName): string
    {
        $parts = preg_split('/\s+/', trim($displayName));
        $letters = array_map(static fn ($p) => mb_strtoupper(mb_substr($p, 0, 1)), array_filter($parts));

        return implode('', array_slice($letters, 0, 2)) ?: '--';
    }
}
