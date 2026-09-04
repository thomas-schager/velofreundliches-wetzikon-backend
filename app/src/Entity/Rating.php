<?php

namespace App\Entity;

use App\Repository\RatingRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Maps onto database/schema.sql's `ratings` table exactly (fixed 5-row registry).
 */
#[ORM\Entity(repositoryClass: RatingRepository::class)]
#[ORM\Table(name: 'ratings')]
class Rating
{
    #[ORM\Id]
    #[ORM\Column(name: 'rating', type: 'smallint', options: ['unsigned' => true])]
    private int $rating;

    #[ORM\Column(name: 'label', type: 'string', length: 32)]
    private string $label;

    #[ORM\Column(name: 'color', type: 'string', length: 7)]
    private string $color;

    public function getRating(): int
    {
        return $this->rating;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getColor(): string
    {
        return $this->color;
    }
}
