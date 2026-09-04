<?php

namespace App\Entity;

use App\Repository\RouteTypeRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Maps onto database/schema.sql's `route_types` table exactly.
 */
#[ORM\Entity(repositoryClass: RouteTypeRepository::class)]
#[ORM\Table(name: 'route_types')]
class RouteType
{
    #[ORM\Id]
    #[ORM\Column(name: '`key`', type: 'string', length: 64)]
    private string $key;

    #[ORM\Column(name: 'label', type: 'string', length: 128)]
    private string $label;

    #[ORM\Column(name: 'color', type: 'string', length: 7)]
    private string $color;

    #[ORM\Column(name: 'weight', type: 'decimal', precision: 3, scale: 1)]
    private string $weight;

    #[ORM\Column(name: 'band', type: 'boolean')]
    private bool $band = false;

    #[ORM\Column(name: 'band_style', type: 'string', length: 16, nullable: true)]
    private ?string $bandStyle = null;

    #[ORM\Column(name: 'band_scale', type: 'decimal', precision: 3, scale: 2, nullable: true)]
    private ?string $bandScale = null;

    #[ORM\Column(name: 'no_direction', type: 'boolean')]
    private bool $noDirection = false;

    #[ORM\Column(name: 'sort_order', type: 'smallint', options: ['unsigned' => true])]
    private int $sortOrder = 0;

    public function getKey(): string
    {
        return $this->key;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getColor(): string
    {
        return $this->color;
    }

    public function getWeight(): float
    {
        return (float) $this->weight;
    }

    public function isBand(): bool
    {
        return $this->band;
    }

    public function getBandStyle(): ?string
    {
        return $this->bandStyle;
    }

    public function getBandScale(): ?float
    {
        return $this->bandScale !== null ? (float) $this->bandScale : null;
    }

    public function isNoDirection(): bool
    {
        return $this->noDirection;
    }

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }
}
