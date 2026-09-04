<?php

namespace App\Service;

use App\Entity\AdminUser;
use App\Entity\AuthChallenge;
use App\Repository\AdminUserRepository;
use App\Repository\AuthChallengeRepository;
use App\Service\Exception\ExpiredChallengeException;
use App\Service\Exception\InvalidChallengeException;
use App\Service\Exception\InvalidCredentialsException;
use App\Service\Exception\ValidationException;
use DateInterval;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Login (email + password + emailed 6-digit code) and password-reset flows. One service, called
 * both by the JSON /auth/* API controller and by the Twig admin-UI pages' client-side JS hitting
 * that same API -- see docs/api-implementation-strategy.md §3.1 for why this must be one code
 * path per business action.
 *
 * Dev-only simplification (no real SMTP configured locally): the 6-digit code is always logged
 * via Monolog, and this service also returns the plaintext code so callers can surface a
 * "Dev-Modus" banner -- see AuthController / templates/auth/verify.html.twig.
 */
class AuthService
{
    private const CHALLENGE_TTL_MINUTES = 10;
    private const MAX_ATTEMPTS = 5;

    public function __construct(
        private readonly AdminUserRepository $adminUsers,
        private readonly AuthChallengeRepository $challenges,
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly LoggerInterface $logger,
        private readonly MailerInterface $mailer,
        private readonly string $environment,
    ) {
    }

    /**
     * Step 1 of login: validate email+password, issue a login challenge (emailed code).
     *
     * @return array{challengeToken: string, maskedEmail: string, devCode: ?string}
     */
    public function requestLoginChallenge(string $email, string $password): array
    {
        $user = $this->adminUsers->findOneByEmail($email);
        if ($user === null || !$user->isActive() || !$this->passwordHasher->isPasswordValid($user, $password)) {
            throw new InvalidCredentialsException('E-Mail-Adresse oder Passwort ist falsch.');
        }

        [$challenge, $code] = $this->createChallenge(AuthChallenge::PURPOSE_LOGIN, $user);

        $this->logger->info('2FA login code generated', ['email' => $user->getEmail(), 'code' => $code]);

        return [
            'challengeToken' => $challenge->getChallengeToken(),
            'maskedEmail' => $this->maskEmail($user->getEmail()),
            'devCode' => $this->environment === 'dev' ? $code : null,
        ];
    }

    /**
     * Step 2 of login: verify the code, return the now-authenticated AdminUser. Establishing the
     * actual session is the security firewall's job (see src/Security/LoginVerifyAuthenticator.php)
     * -- this method only validates the challenge and hands back who logged in.
     */
    public function verifyLoginChallenge(string $challengeToken, string $code): AdminUser
    {
        $challenge = $this->consumeChallenge($challengeToken, AuthChallenge::PURPOSE_LOGIN, $code);

        $user = $challenge->getAdminUser();
        if ($user === null) {
            throw new InvalidChallengeException('Der Code ist ungültig oder abgelaufen.');
        }

        return $user;
    }

    /**
     * Always "succeeds" outwardly regardless of whether the email exists (avoid leaking which
     * addresses have an account) -- see openapi.yaml's /auth/forgot-password description.
     *
     * @return array{challengeToken: string, devCode: ?string}
     */
    public function requestPasswordReset(string $email): array
    {
        $user = $this->adminUsers->findOneByEmail($email);
        [$challenge, $code] = $this->createChallenge(AuthChallenge::PURPOSE_PASSWORD_RESET, $user);

        if ($user !== null) {
            $this->logger->info('2FA password-reset code generated', ['email' => $user->getEmail(), 'code' => $code]);
        }

        return [
            'challengeToken' => $challenge->getChallengeToken(),
            'devCode' => $this->environment === 'dev' ? $code : null,
        ];
    }

    public function verifyPasswordResetChallenge(string $challengeToken, string $code): string
    {
        $challenge = $this->consumeChallenge($challengeToken, AuthChallenge::PURPOSE_PASSWORD_RESET, $code);

        if ($challenge->getAdminUser() === null) {
            // Unknown email at request time -- there is nothing to reset. Fail the same way an
            // invalid code would, so the response shape doesn't leak account existence either.
            throw new InvalidChallengeException('Der Code ist ungültig oder abgelaufen.');
        }

        $resetToken = bin2hex(random_bytes(32));
        $challenge->setResetToken($resetToken);
        $this->em->flush();

        return $resetToken;
    }

    public function resetPassword(string $resetToken, string $newPassword): void
    {
        if (mb_strlen($newPassword) < 12) {
            throw new ValidationException('Password does not meet policy.', ['newPassword' => 'Mindestens 12 Zeichen.']);
        }

        $challenge = $this->challenges->findOneByResetToken($resetToken);
        if ($challenge === null || $challenge->getResetToken() === null) {
            throw new InvalidChallengeException('Reset token invalid, expired, or already used.');
        }
        if ($challenge->isExpired()) {
            throw new ExpiredChallengeException('Reset token expired.');
        }

        $user = $challenge->getAdminUser();
        if ($user === null) {
            throw new InvalidChallengeException('Reset token invalid, expired, or already used.');
        }

        $user->setPasswordHash($this->passwordHasher->hashPassword($user, $newPassword));
        $user->touch();
        // Single-use: null it out so the same reset token can't be replayed.
        $challenge->setResetToken(null);

        $this->em->flush();
    }

    /**
     * @return array{0: AuthChallenge, 1: string} the challenge and the plaintext code (never persisted)
     */
    private function createChallenge(string $purpose, ?AdminUser $user): array
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $challenge = new AuthChallenge();
        $challenge->setPurpose($purpose)
            ->setAdminUser($user)
            ->setChallengeToken(bin2hex(random_bytes(32)))
            ->setCodeHash(password_hash($code, PASSWORD_BCRYPT))
            ->setExpiresAt((new DateTimeImmutable())->add(new DateInterval('PT' . self::CHALLENGE_TTL_MINUTES . 'M')));

        $this->em->persist($challenge);
        $this->em->flush();

        if ($user !== null) {
            $this->sendCodeEmail($user->getEmail(), $code);
        }

        return [$challenge, $code];
    }

    /**
     * Actually routes through Symfony Mailer (MAILER_DSN=null://null locally -- no SMTP
     * configured, see README.md's Status section). The code itself reaches the developer via
     * Monolog + the dev-mode verify-page banner (see requestLoginChallenge/requestPasswordReset
     * callers) since the null transport swallows the message; this call exists so a real
     * MAILER_DSN can be dropped in later without any other code changing.
     */
    private function sendCodeEmail(string $to, string $code): void
    {
        try {
            $this->mailer->send((new Email())
                ->from('no-reply@velofreundliches-wetzikon.ch')
                ->to($to)
                ->subject('Ihr Bestätigungscode')
                ->text("Ihr 6-stelliger Code lautet: {$code}\nGültig für 10 Minuten."));
        } catch (TransportExceptionInterface $e) {
            $this->logger->warning('Could not send 2FA code email', ['exception' => $e->getMessage()]);
        }
    }

    private function consumeChallenge(string $challengeToken, string $purpose, string $code): AuthChallenge
    {
        $challenge = $this->challenges->findOneByChallengeToken($challengeToken);
        if ($challenge === null || $challenge->getPurpose() !== $purpose || $challenge->isConsumed()) {
            throw new InvalidChallengeException('Der Code ist ungültig oder abgelaufen.');
        }
        if ($challenge->isExpired()) {
            throw new ExpiredChallengeException('Code expired -- request a new one.');
        }
        if ($challenge->getAttempts() >= self::MAX_ATTEMPTS) {
            throw new InvalidChallengeException('Zu viele Versuche. Bitte fordern Sie einen neuen Code an.');
        }

        if (!password_verify($code, $challenge->getCodeHash())) {
            $challenge->incrementAttempts();
            $this->em->flush();
            throw new InvalidChallengeException('Der Code ist ungültig oder abgelaufen.');
        }

        $challenge->setConsumedAt(new DateTimeImmutable());
        $this->em->flush();

        return $challenge;
    }

    private function maskEmail(string $email): string
    {
        [$local, $domain] = explode('@', $email, 2) + [1 => ''];
        $first = mb_substr($local, 0, 1);

        return $first . str_repeat('•', max(3, mb_strlen($local) - 1)) . '@' . $domain;
    }
}
