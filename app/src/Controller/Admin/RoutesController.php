<?php

namespace App\Controller\Admin;

use App\Entity\Report;
use App\Repository\ReportRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Server-rendered shell for the route editor -- the page itself only lays out the map/panels and
 * loads assets/routes-editor.js, which does the actual work by calling the JSON endpoints in
 * Controller\Api\AdminRoutesController (GET/PUT /admin/routes, POST /admin/routes/diff,
 * GET/POST /admin/routes/backups...). No route data is fetched server-side -- keeping this
 * controller a plain page render, same reasoning as MeldungenController's list/detail pages,
 * matches "one code path" (docs/api-implementation-strategy.md §3.1): the editor and any future
 * API consumer read through the exact same endpoints, not a Twig-only shortcut.
 */
class RoutesController extends AbstractController
{
    public function __construct(private readonly ReportRepository $reports)
    {
    }

    #[Route('/routen', name: 'admin_routes', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('admin/routes.html.twig', [
            'nav_page' => 'routen',
            'pending_count' => $this->reports->countByStatus(Report::STATUS_PENDING_REVIEW),
        ]);
    }
}
