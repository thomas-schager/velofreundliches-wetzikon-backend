<?php

namespace App\Controller\Admin;

use App\Entity\Report;
use App\Repository\ReportRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Stub page -- the route editor is explicitly out of scope, see README.md. */
class RoutesPlaceholderController extends AbstractController
{
    public function __construct(private readonly ReportRepository $reports)
    {
    }

    #[Route('/routen', name: 'admin_routes_placeholder', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('admin/routes_placeholder.html.twig', [
            'nav_page' => 'routen',
            'pending_count' => $this->reports->countByStatus(Report::STATUS_PENDING_REVIEW),
        ]);
    }
}
