<?php

namespace App\Repository;

use App\Entity\RouteBackup;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RouteBackup>
 */
class RouteBackupRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RouteBackup::class);
    }

    /** @return RouteBackup[] */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('b')
            ->orderBy('b.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * The backup immediately AFTER $id in time -- ids are assigned in strictly increasing
     * creation order, so this is a cheap, unambiguous id comparison rather than a createdAt
     * one (which could theoretically tie at one-second resolution). Null means $id is the
     * newest backup there is. See RouteEditingService::restoreBackup()'s docblock for why this
     * matters: a backup's own file is the state BEFORE its save, so finding "what that version
     * actually looked like" means looking at the next-newer backup's file instead.
     */
    public function findNextNewer(int $id): ?RouteBackup
    {
        return $this->createQueryBuilder('b')
            ->where('b.id > :id')
            ->setParameter('id', $id)
            ->orderBy('b.id', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
