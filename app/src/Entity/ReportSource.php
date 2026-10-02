<?php

namespace App\Entity;

use App\Repository\ReportSourceRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Which public map/tool a Report was submitted from (e.g. velomelder-gelbes-band.html ->
 * "Vorschlag gelbes Band"). Small, stable, pre-seeded reference table -- same pattern as
 * Rating/RouteType, and like Report::rating, Report itself keeps this as a plain string column
 * (not a Doctrine relation) with label lookups done separately via findAllOrdered(), not an
 * eager/lazy association -- see MeldungenController's rating_labels for the precedent.
 */
#[ORM\Entity(repositoryClass: ReportSourceRepository::class)]
#[ORM\Table(name: 'report_sources')]
class ReportSource
{
    #[ORM\Id]
    #[ORM\Column(name: '`key`', type: 'string', length: 64)]
    private string $key;

    #[ORM\Column(name: 'label', type: 'string', length: 128)]
    private string $label;

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

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }
}
