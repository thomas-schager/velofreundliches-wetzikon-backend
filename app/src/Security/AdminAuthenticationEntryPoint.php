<?php

namespace App\Security;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

/**
 * Unauthenticated access to a protected route: JSON 401 for API consumers (/admin/*, /auth/me,
 * /auth/logout), a 302 to the Twig login page for everything else (/dashboard, /meldungen*).
 */
class AdminAuthenticationEntryPoint implements AuthenticationEntryPointInterface
{
    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        $path = $request->getPathInfo();

        if (str_starts_with($path, '/admin') || str_starts_with($path, '/auth/')) {
            return new JsonResponse(['error' => 'unauthorized', 'message' => 'No valid admin session.'], 401);
        }

        return new RedirectResponse('/login');
    }
}
