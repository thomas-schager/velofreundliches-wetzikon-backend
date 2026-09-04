<?php

namespace App\Controller\Api;

use App\Entity\AdminUser;
use App\Service\AuthService;
use App\Service\Exception\ExpiredChallengeException;
use App\Service\Exception\InvalidChallengeException;
use App\Service\Exception\InvalidCredentialsException;
use App\Service\Exception\ValidationException;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * JSON auth endpoints -- see api/openapi.yaml "Auth". Step 2 of login (POST /auth/login/verify)
 * is handled by App\Security\LoginVerifyAuthenticator instead of a plain controller action,
 * because it's the one request that actually establishes the session; this controller only
 * carries the fallback (unreachable in normal operation, see that class's docblock).
 */
class AuthController extends AbstractApiController
{
    public function __construct(
        private readonly AuthService $authService,
        private readonly Security $security,
    ) {
    }

    #[Route('/auth/login', name: 'api_auth_login', methods: ['POST'])]
    public function login(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];
        $email = (string) ($data['email'] ?? '');
        $password = (string) ($data['password'] ?? '');

        try {
            $result = $this->authService->requestLoginChallenge($email, $password);
        } catch (InvalidCredentialsException $e) {
            return $this->errorResponse('invalid_credentials', $e->getMessage(), 401);
        }

        return new JsonResponse($result);
    }

    #[Route('/auth/login/verify', name: 'api_auth_login_verify', methods: ['POST'])]
    public function loginVerifyFallback(): JsonResponse
    {
        // Reached only if the LoginVerifyAuthenticator didn't handle the request (should not
        // happen in normal operation -- its supports() matches this exact path+method).
        return $this->errorResponse('invalid', 'Code incorrect or challenge token unknown.', 401);
    }

    #[Route('/auth/forgot-password', name: 'api_auth_forgot_password', methods: ['POST'])]
    public function forgotPassword(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];
        $email = (string) ($data['email'] ?? '');

        $result = $this->authService->requestPasswordReset($email);

        return new JsonResponse($result);
    }

    #[Route('/auth/forgot-password/verify', name: 'api_auth_forgot_password_verify', methods: ['POST'])]
    public function forgotPasswordVerify(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];
        $challengeToken = (string) ($data['challengeToken'] ?? '');
        $code = (string) ($data['code'] ?? '');

        try {
            $resetToken = $this->authService->verifyPasswordResetChallenge($challengeToken, $code);
        } catch (ExpiredChallengeException $e) {
            return $this->errorResponse('expired', $e->getMessage(), 410);
        } catch (InvalidChallengeException $e) {
            return $this->errorResponse('invalid', $e->getMessage(), 401);
        }

        return new JsonResponse(['resetToken' => $resetToken]);
    }

    #[Route('/auth/reset-password', name: 'api_auth_reset_password', methods: ['POST'])]
    public function resetPassword(Request $request): Response
    {
        $data = json_decode($request->getContent(), true) ?? [];
        $resetToken = (string) ($data['resetToken'] ?? '');
        $newPassword = (string) ($data['newPassword'] ?? '');

        try {
            $this->authService->resetPassword($resetToken, $newPassword);
        } catch (ValidationException $e) {
            return $this->errorResponse('validation_error', $e->getMessage(), 400, $e->getErrors());
        } catch (ExpiredChallengeException|InvalidChallengeException $e) {
            return $this->errorResponse('invalid', $e->getMessage(), 401);
        }

        return new Response(null, 200);
    }

    #[Route('/auth/logout', name: 'api_auth_logout', methods: ['POST'])]
    public function logout(): Response
    {
        $this->security->logout(false);

        return new Response(null, 204);
    }

    #[Route('/auth/me', name: 'api_auth_me', methods: ['GET'])]
    public function me(#[CurrentUser] ?AdminUser $user): JsonResponse
    {
        if ($user === null) {
            return $this->errorResponse('unauthorized', 'No valid admin session.', 401);
        }

        return new JsonResponse([
            'email' => $user->getEmail(),
            'displayName' => $user->getDisplayName(),
            'role' => $user->getRole(),
        ]);
    }
}
