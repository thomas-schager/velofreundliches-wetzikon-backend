<?php

namespace App\Repository;

use App\Entity\RouteFeature;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RouteFeature>
 */
class RouteFeatureRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RouteFeature::class);
    }

    /** @return RouteFeature[] */
    public function findAll(): array
    {
        return $this->createQueryBuilder('f')
            ->orderBy('f.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
