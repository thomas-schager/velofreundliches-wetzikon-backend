<?php

namespace App\Repository;

use App\Entity\RouteType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RouteType>
 */
class RouteTypeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RouteType::class);
    }

    /** @return RouteType[] */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('t')
            ->orderBy('t.sortOrder', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
