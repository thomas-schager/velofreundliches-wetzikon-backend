<?php

namespace App\Entity;

use App\Repository\RouteBackupRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * One row per saved change to route_features -- see RouteEditingService::save(). `filePath` points
 * at a GeoJSON snapshot of the route network as it was *before* this change, written to
 * var/route-backups/ (not web-served, not committed -- runtime data). This is deliberately a
 * simple linear backup trail, not full version history/branching: PUT /admin/routes stays a full
 * replace, restoring a backup is just another tracked save (see restoreBackup()) -- see
 * docs/api-implementation-strategy.md §7.
 */
#[ORM\Entity(repositoryClass: RouteBackupRepository::class)]
#[ORM\Table(name: 'route_backups')]
class RouteBackup
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'id', type: 'bigint', options: ['unsigned' => true])]
    private ?string $id = null;

    #[ORM\Column(name: 'file_path', type: 'string', length: 255)]
    private string $filePath;

    #[ORM\ManyToOne(targetEntity: AdminUser::class)]
    #[ORM\JoinColumn(name: 'created_by', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?AdminUser $createdBy = null;

    #[ORM\Column(name: 'added_count', type: 'smallint', options: ['unsigned' => true])]
    private int $addedCount = 0;

    #[ORM\Column(name: 'removed_count', type: 'smallint', options: ['unsigned' => true])]
    private int $removedCount = 0;

    #[ORM\Column(name: 'modified_count', type: 'smallint', options: ['unsigned' => true])]
    private int $modifiedCount = 0;

    #[ORM\Column(name: 'summary', type: 'text')]
    private string $summary;

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

    public function getFilePath(): string
    {
        return $this->filePath;
    }

    public function setFilePath(string $filePath): static
    {
        $this->filePath = $filePath;

        return $this;
    }

    public function getCreatedBy(): ?AdminUser
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?AdminUser $createdBy): static
    {
        $this->createdBy = $createdBy;

        return $this;
    }

    public function getAddedCount(): int
    {
        return $this->addedCount;
    }

    public function getRemovedCount(): int
    {
        return $this->removedCount;
    }

    public function getModifiedCount(): int
    {
        return $this->modifiedCount;
    }

    public function setCounts(int $added, int $removed, int $modified): static
    {
        $this->addedCount = $added;
        $this->removedCount = $removed;
        $this->modifiedCount = $modified;

        return $this;
    }

    public function getSummary(): string
    {
        return $this->summary;
    }

    public function setSummary(string $summary): static
    {
        $this->summary = $summary;

        return $this;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
