<?php

namespace App\Controller\Admin;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Server-rendered auth pages (login/verify/forgot-password/reset-password). Each page's form
 * submits client-side (fetch) to the matching JSON endpoint in Controller\Api\AuthController /
 * the LoginVerifyAuthenticator -- see those classes' docblocks for why the actual session
 * establishment can't happen in a plain Twig-page controller.
 */
class AuthPagesController extends AbstractController
{
    #[Route('/', name: 'admin_root', methods: ['GET'])]
    public function root(): Response
    {
        return $this->redirectToRoute('admin_login');
    }

    #[Route('/login', name: 'admin_login', methods: ['GET'])]
    public function login(): Response
    {
        return $this->render('auth/login.html.twig');
    }

    #[Route('/verify', name: 'admin_verify', methods: ['GET'])]
    public function verify(Request $request): Response
    {
        return $this->render('auth/verify.html.twig', [
            'flow' => $request->query->get('flow', 'login'),
            'challenge_token' => $request->query->get('challengeToken', ''),
            'masked_email' => $request->query->get('maskedEmail'),
            'dev_code' => $request->query->get('devCode'),
        ]);
    }

    #[Route('/forgot-password', name: 'admin_forgot_password', methods: ['GET'])]
    public function forgotPassword(): Response
    {
        return $this->render('auth/forgot_password.html.twig');
    }

    #[Route('/reset-password', name: 'admin_reset_password', methods: ['GET'])]
    public function resetPassword(): Response
    {
        return $this->render('auth/reset_password.html.twig');
    }
}
