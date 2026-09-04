<?php

namespace App\Entity;

use App\Repository\RouteFeatureRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Maps onto database/schema.sql's `route_features` table exactly. `coordinates` stores the
 * GeoJSON LineString's coordinate array verbatim ([[lng,lat], ...], WGS84) as JSON.
 */
#[ORM\Entity(repositoryClass: RouteFeatureRepository::class)]
#[ORM\Table(name: 'route_features')]
class RouteFeature
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'id', type: 'bigint', options: ['unsigned' => true])]
    private ?string $id = null;

    #[ORM\ManyToOne(targetEntity: RouteType::class)]
    #[ORM\JoinColumn(name: 'route_type_key', referencedColumnName: '`key`', nullable: false)]
    private RouteType $routeType;

    #[ORM\Column(name: 'direction', type: 'string', length: 16, nullable: true)]
    private ?string $direction = null;

    /** @var array<int, array{0: float, 1: float}> */
    #[ORM\Column(name: 'coordinates', type: 'json')]
    private array $coordinates = [];

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
        $this->updatedAt = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id !== null ? (int) $this->id : null;
    }

    public function getRouteType(): RouteType
    {
        return $this->routeType;
    }

    public function setRouteType(RouteType $routeType): static
    {
        $this->routeType = $routeType;

        return $this;
    }

    public function getDirection(): ?string
    {
        return $this->direction;
    }

    public function setDirection(?string $direction): static
    {
        $this->direction = $direction;

        return $this;
    }

    /** @return array<int, array{0: float, 1: float}> */
    public function getCoordinates(): array
    {
        return $this->coordinates;
    }

    /** @param array<int, array{0: float, 1: float}> $coordinates */
    public function setCoordinates(array $coordinates): static
    {
        $this->coordinates = $coordinates;

        return $this;
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
}
