<?php

namespace App\Security;

use App\Service\AuthService;
use App\Service\Exception\ExpiredChallengeException;
use App\Service\Exception\InvalidChallengeException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

/**
 * Step 2 of admin login (POST /auth/login/verify): the only moment a real Symfony session gets
 * established (httpOnly cookie, see openapi.yaml's sessionAuth scheme). Step 1 (email+password,
 * see AuthController::login) deliberately does NOT authenticate -- it only issues a challenge --
 * so the flow needs a dedicated authenticator bound to this exact check path rather than the
 * stock form_login authenticator.
 */
class LoginVerifyAuthenticator extends AbstractAuthenticator
{
    public function __construct(private readonly AuthService $authService)
    {
    }

    public function supports(Request $request): ?bool
    {
        return $request->getPathInfo() === '/auth/login/verify' && $request->isMethod('POST');
    }

    public function authenticate(Request $request): Passport
    {
        $data = json_decode($request->getContent(), true) ?? [];
        $challengeToken = (string) ($data['challengeToken'] ?? '');
        $code = (string) ($data['code'] ?? '');

        if ($challengeToken === '' || $code === '') {
            throw new CustomUserMessageAuthenticationException('challengeToken and code are required.');
        }

        try {
            $user = $this->authService->verifyLoginChallenge($challengeToken, $code);
        } catch (InvalidChallengeException|ExpiredChallengeException $e) {
            throw new CustomUserMessageAuthenticationException($e->getMessage(), [], 0, $e);
        }

        return new SelfValidatingPassport(new UserBadge($user->getUserIdentifier(), fn () => $user));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        /** @var \App\Entity\AdminUser $user */
        $user = $token->getUser();

        return new JsonResponse([
            'email' => $user->getEmail(),
            'displayName' => $user->getDisplayName(),
            'role' => $user->getRole(),
        ]);
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        $previous = $exception->getPrevious();
        if ($previous instanceof ExpiredChallengeException) {
            return new JsonResponse(['error' => 'expired', 'message' => 'Code expired — request a new one via /auth/login again.'], 410);
        }

        return new JsonResponse(['error' => 'invalid', 'message' => 'Code incorrect or challenge token unknown.'], 401);
    }
}
