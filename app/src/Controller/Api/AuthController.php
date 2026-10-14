<?php

namespace App\Controller\Api;

use App\Entity\AdminUser;
use App\Service\AuthService;
use App\Service\Exception\ExpiredChallengeException;
use App\Service\Exception\InvalidChallengeException;
use App\Service\Exception\InvalidCredentialsException;
use App\Service\Exception\ValidationException;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * JSON auth endpoints -- see api/openapi.yaml "Auth". Step 2 of login (POST /auth/login/verify)
 * is handled by App\Security\LoginVerifyAuthenticator instead of a plain controller action,
 * because it's the one request that actually establishes the session; this controller only
 * carries the fallback (unreachable in normal operation, see that class's docblock).
 *
 * login()/forgotPassword() are rate-limited by both IP and the submitted email (see
 * config/packages/rate_limiter.yaml) -- IP alone doesn't stop a distributed attack on one
 * account, and email alone doesn't stop one machine grinding through many accounts. Consumed on
 * every attempt regardless of outcome, so a correct password without the 2FA code still counts
 * against the limit. forgotPassword() needs this independently of login()'s limiter: it needs no
 * credentials at all and, by design (see AuthService::requestPasswordReset()'s docblock), always
 * "succeeds" and emails a fresh code -- unrestricted, that's a mail-bomb vector against the
 * account's real inbox.
 */
class AuthController extends AbstractApiController
{
    public function __construct(
        private readonly AuthService $authService,
        private readonly Security $security,
        #[Autowire(service: 'limiter.login_ip')]
        private readonly RateLimiterFactory $loginIpLimiter,
        #[Autowire(service: 'limiter.login_email')]
        private readonly RateLimiterFactory $loginEmailLimiter,
        #[Autowire(service: 'limiter.forgot_password_ip')]
        private readonly RateLimiterFactory $forgotPasswordIpLimiter,
        #[Autowire(service: 'limiter.forgot_password_email')]
        private readonly RateLimiterFactory $forgotPasswordEmailLimiter,
        private readonly LoggerInterface $securityLogger,
    ) {
    }

    #[Route('/auth/login', name: 'api_auth_login', methods: ['POST'])]
    public function login(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];
        $email = (string) ($data['email'] ?? '');
        $password = (string) ($data['password'] ?? '');

        $ipLimit = $this->loginIpLimiter->create($request->getClientIp())->consume();
        $emailLimit = $this->loginEmailLimiter->create(mb_strtolower(trim($email)))->consume();
        if (!$ipLimit->isAccepted() || !$emailLimit->isAccepted()) {
            $this->securityLogger->warning('Rate limit exceeded on /auth/login', [
                'ip' => $request->getClientIp(),
                'email' => $email,
                'ipLimited' => !$ipLimit->isAccepted(),
                'emailLimited' => !$emailLimit->isAccepted(),
            ]);

            return $this->rateLimitedResponse($ipLimit->isAccepted() ? $emailLimit : $ipLimit);
        }

        try {
            $result = $this->authService->requestLoginChallenge($email, $password, $request->headers->get('X-Test-Bypass-Token'));
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

        $ipLimit = $this->forgotPasswordIpLimiter->create($request->getClientIp())->consume();
        $emailLimit = $this->forgotPasswordEmailLimiter->create(mb_strtolower(trim($email)))->consume();
        if (!$ipLimit->isAccepted() || !$emailLimit->isAccepted()) {
            $this->securityLogger->warning('Rate limit exceeded on /auth/forgot-password', [
                'ip' => $request->getClientIp(),
                'email' => $email,
                'ipLimited' => !$ipLimit->isAccepted(),
                'emailLimited' => !$emailLimit->isAccepted(),
            ]);

            return $this->rateLimitedResponse($ipLimit->isAccepted() ? $emailLimit : $ipLimit);
        }

        $result = $this->authService->requestPasswordReset($email, $request->headers->get('X-Test-Bypass-Token'));

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
