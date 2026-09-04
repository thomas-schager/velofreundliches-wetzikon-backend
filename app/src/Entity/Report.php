<?php

namespace App\Entity;

use App\Repository\ReportRepository;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Maps onto database/schema.sql's `reports` table exactly. Status model:
 * pending_email_confirmation -> (link clicked) -> pending_review -> (admin) -> published|declined
 */
#[ORM\Entity(repositoryClass: ReportRepository::class)]
#[ORM\Table(name: 'reports')]
class Report
{
    public const STATUS_PENDING_EMAIL_CONFIRMATION = 'pending_email_confirmation';
    public const STATUS_PENDING_REVIEW = 'pending_review';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_DECLINED = 'declined';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'id', type: 'bigint', options: ['unsigned' => true])]
    private ?string $id = null;

    #[ORM\Column(name: 'lat', type: 'decimal', precision: 9, scale: 6)]
    private string $lat;

    #[ORM\Column(name: 'lng', type: 'decimal', precision: 9, scale: 6)]
    private string $lng;

    #[ORM\Column(name: 'rating', type: 'smallint', options: ['unsigned' => true])]
    private int $rating;

    #[ORM\Column(name: 'comment', type: 'string', length: 2000)]
    private string $comment;

    #[ORM\Column(name: 'name', type: 'string', length: 255, nullable: true)]
    private ?string $name = null;

    #[ORM\Column(name: 'anonymous', type: 'boolean')]
    private bool $anonymous = true;

    #[ORM\Column(name: 'address', type: 'string', length: 255, nullable: true)]
    private ?string $address = null;

    #[ORM\Column(name: 'address_distance_m', type: 'decimal', precision: 6, scale: 1, nullable: true)]
    private ?string $addressDistanceM = null;

    #[ORM\Column(name: 'email', type: 'string', length: 255)]
    private string $email;

    #[ORM\Column(name: 'email_confirmed', type: 'boolean')]
    private bool $emailConfirmed = false;

    #[ORM\Column(name: 'status', type: 'string', length: 32)]
    private string $status = self::STATUS_PENDING_EMAIL_CONFIRMATION;

    #[ORM\Column(name: 'confirmation_token', type: 'string', length: 128, nullable: true, unique: true)]
    private ?string $confirmationToken = null;

    #[ORM\Column(name: 'confirmation_expires_at', type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $confirmationExpiresAt = null;

    #[ORM\Column(name: 'internal_note', type: 'text', nullable: true)]
    private ?string $internalNote = null;

    #[ORM\ManyToOne(targetEntity: AdminUser::class)]
    #[ORM\JoinColumn(name: 'moderated_by', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?AdminUser $moderatedBy = null;

    #[ORM\Column(name: 'moderated_at', type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $moderatedAt = null;

    #[ORM\Version]
    #[ORM\Column(name: 'version', type: 'integer', options: ['unsigned' => true])]
    private int $version = 1;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private DateTimeImmutable $updatedAt;

    /** @var Collection<int, ReportPhoto> */
    #[ORM\OneToMany(targetEntity: ReportPhoto::class, mappedBy: 'report', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['sortOrder' => 'ASC'])]
    private Collection $photos;

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
        $this->updatedAt = new DateTimeImmutable();
        $this->photos = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id !== null ? (int) $this->id : null;
    }

    public function getLat(): float
    {
        return (float) $this->lat;
    }

    public function setLat(float $lat): static
    {
        $this->lat = (string) $lat;

        return $this;
    }

    public function getLng(): float
    {
        return (float) $this->lng;
    }

    public function setLng(float $lng): static
    {
        $this->lng = (string) $lng;

        return $this;
    }

    public function getRating(): int
    {
        return $this->rating;
    }

    public function setRating(int $rating): static
    {
        $this->rating = $rating;

        return $this;
    }

    public function getComment(): string
    {
        return $this->comment;
    }

    public function setComment(string $comment): static
    {
        $this->comment = $comment;

        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function isAnonymous(): bool
    {
        return $this->anonymous;
    }

    public function setAnonymous(bool $anonymous): static
    {
        $this->anonymous = $anonymous;

        return $this;
    }

    public function getAddress(): ?string
    {
        return $this->address;
    }

    public function setAddress(?string $address): static
    {
        $this->address = $address;

        return $this;
    }

    public function getAddressDistanceM(): ?float
    {
        return $this->addressDistanceM !== null ? (float) $this->addressDistanceM : null;
    }

    public function setAddressDistanceM(?float $addressDistanceM): static
    {
        $this->addressDistanceM = $addressDistanceM !== null ? (string) $addressDistanceM : null;

        return $this;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;

        return $this;
    }

    public function isEmailConfirmed(): bool
    {
        return $this->emailConfirmed;
    }

    public function setEmailConfirmed(bool $emailConfirmed): static
    {
        $this->emailConfirmed = $emailConfirmed;

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getConfirmationToken(): ?string
    {
        return $this->confirmationToken;
    }

    public function setConfirmationToken(?string $confirmationToken): static
    {
        $this->confirmationToken = $confirmationToken;

        return $this;
    }

    public function getConfirmationExpiresAt(): ?DateTimeImmutable
    {
        return $this->confirmationExpiresAt;
    }

    public function setConfirmationExpiresAt(?DateTimeImmutable $confirmationExpiresAt): static
    {
        $this->confirmationExpiresAt = $confirmationExpiresAt;

        return $this;
    }

    public function getInternalNote(): ?string
    {
        return $this->internalNote;
    }

    public function setInternalNote(?string $internalNote): static
    {
        $this->internalNote = $internalNote;

        return $this;
    }

    public function getModeratedBy(): ?AdminUser
    {
        return $this->moderatedBy;
    }

    public function setModeratedBy(?AdminUser $moderatedBy): static
    {
        $this->moderatedBy = $moderatedBy;

        return $this;
    }

    public function getModeratedAt(): ?DateTimeImmutable
    {
        return $this->moderatedAt;
    }

    public function setModeratedAt(?DateTimeImmutable $moderatedAt): static
    {
        $this->moderatedAt = $moderatedAt;

        return $this;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function touch(): void
    {
        $this->updatedAt = new DateTimeImmutable();
    }

    /** @return Collection<int, ReportPhoto> */
    public function getPhotos(): Collection
    {
        return $this->photos;
    }

    public function addPhoto(ReportPhoto $photo): static
    {
        if (!$this->photos->contains($photo)) {
            $this->photos->add($photo);
            $photo->setReport($this);
        }

        return $this;
    }
}
