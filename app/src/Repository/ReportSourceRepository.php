<?php

namespace App\Repository;

use App\Entity\ReportSource;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ReportSource>
 */
class ReportSourceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ReportSource::class);
    }

    /** @return ReportSource[] */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('s')
            ->orderBy('s.sortOrder', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
