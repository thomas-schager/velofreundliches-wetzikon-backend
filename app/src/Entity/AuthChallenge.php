<?php

namespace App\Entity;

use App\Repository\AuthChallengeRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Maps onto database/schema.sql's `auth_challenges` table exactly. Backs both
 * /auth/login + /auth/login/verify and /auth/forgot-password + /auth/forgot-password/verify.
 */
#[ORM\Entity(repositoryClass: AuthChallengeRepository::class)]
#[ORM\Table(name: 'auth_challenges')]
class AuthChallenge
{
    public const PURPOSE_LOGIN = 'login';
    public const PURPOSE_PASSWORD_RESET = 'password_reset';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'id', type: 'bigint', options: ['unsigned' => true])]
    private ?string $id = null;

    #[ORM\ManyToOne(targetEntity: AdminUser::class)]
    #[ORM\JoinColumn(name: 'admin_user_id', referencedColumnName: 'id', nullable: true, onDelete: 'CASCADE')]
    private ?AdminUser $adminUser = null;

    #[ORM\Column(name: 'purpose', type: 'string', length: 32)]
    private string $purpose;

    #[ORM\Column(name: 'challenge_token', type: 'string', length: 128, unique: true)]
    private string $challengeToken;

    #[ORM\Column(name: 'code_hash', type: 'string', length: 255)]
    private string $codeHash;

    #[ORM\Column(name: 'attempts', type: 'smallint', options: ['unsigned' => true])]
    private int $attempts = 0;

    #[ORM\Column(name: 'reset_token', type: 'string', length: 128, nullable: true, unique: true)]
    private ?string $resetToken = null;

    #[ORM\Column(name: 'expires_at', type: 'datetime_immutable')]
    private DateTimeImmutable $expiresAt;

    #[ORM\Column(name: 'consumed_at', type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $consumedAt = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id !== null ? (int) $this->id : null;
    }

    public function getAdminUser(): ?AdminUser
    {
        return $this->adminUser;
    }

    public function setAdminUser(?AdminUser $adminUser): static
    {
        $this->adminUser = $adminUser;

        return $this;
    }

    public function getPurpose(): string
    {
        return $this->purpose;
    }

    public function setPurpose(string $purpose): static
    {
        $this->purpose = $purpose;

        return $this;
    }

    public function getChallengeToken(): string
    {
        return $this->challengeToken;
    }

    public function setChallengeToken(string $challengeToken): static
    {
        $this->challengeToken = $challengeToken;

        return $this;
    }

    public function getCodeHash(): string
    {
        return $this->codeHash;
    }

    public function setCodeHash(string $codeHash): static
    {
        $this->codeHash = $codeHash;

        return $this;
    }

    public function getAttempts(): int
    {
        return $this->attempts;
    }

    public function incrementAttempts(): static
    {
        $this->attempts++;

        return $this;
    }

    public function getResetToken(): ?string
    {
        return $this->resetToken;
    }

    public function setResetToken(?string $resetToken): static
    {
        $this->resetToken = $resetToken;

        return $this;
    }

    public function getExpiresAt(): DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(DateTimeImmutable $expiresAt): static
    {
        $this->expiresAt = $expiresAt;

        return $this;
    }

    public function getConsumedAt(): ?DateTimeImmutable
    {
        return $this->consumedAt;
    }

    public function setConsumedAt(?DateTimeImmutable $consumedAt): static
    {
        $this->consumedAt = $consumedAt;

        return $this;
    }

    public function isExpired(): bool
    {
        return $this->expiresAt < new DateTimeImmutable();
    }

    public function isConsumed(): bool
    {
        return $this->consumedAt !== null;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
