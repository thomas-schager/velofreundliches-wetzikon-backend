<?php

namespace App\Entity;

use App\Repository\ReportPhotoRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Maps onto database/schema.sql's `report_photos` table exactly.
 */
#[ORM\Entity(repositoryClass: ReportPhotoRepository::class)]
#[ORM\Table(name: 'report_photos')]
class ReportPhoto
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'id', type: 'bigint', options: ['unsigned' => true])]
    private ?string $id = null;

    #[ORM\ManyToOne(targetEntity: Report::class, inversedBy: 'photos')]
    #[ORM\JoinColumn(name: 'report_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Report $report;

    #[ORM\Column(name: 'url', type: 'string', length: 500)]
    private string $url;

    #[ORM\Column(name: 'sort_order', type: 'smallint', options: ['unsigned' => true])]
    private int $sortOrder = 0;

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

    public function getReport(): Report
    {
        return $this->report;
    }

    public function setReport(Report $report): static
    {
        $this->report = $report;

        return $this;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function setUrl(string $url): static
    {
        $this->url = $url;

        return $this;
    }

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function setSortOrder(int $sortOrder): static
    {
        $this->sortOrder = $sortOrder;

        return $this;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
