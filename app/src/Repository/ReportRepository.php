<?php

namespace App\Repository;

use App\Entity\Report;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Report>
 */
class ReportRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Report::class);
    }

    public function findOneByConfirmationToken(string $token): ?Report
    {
        return $this->findOneBy(['confirmationToken' => $token]);
    }

    /** @return Report[] */
    public function findPublished(?int $rating = null): array
    {
        $qb = $this->createQueryBuilder('r')
            ->where('r.status = :status')
            ->setParameter('status', Report::STATUS_PUBLISHED)
            ->orderBy('r.createdAt', 'DESC');

        if ($rating !== null) {
            $qb->andWhere('r.rating = :rating')->setParameter('rating', $rating);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * @return array{items: Report[], total: int}
     */
    public function findForAdmin(?string $status, int $page, int $perPage = 50): array
    {
        $qb = $this->createQueryBuilder('r')
            ->orderBy('r.createdAt', 'DESC');

        if ($status !== null) {
            $qb->andWhere('r.status = :status')->setParameter('status', $status);
        }

        $countQb = clone $qb;
        $total = (int) $countQb->select('COUNT(r.id)')->getQuery()->getSingleScalarResult();

        $items = $qb->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();

        return ['items' => $items, 'total' => $total];
    }

    public function countByStatus(string $status): int
    {
        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->where('r.status = :status')
            ->setParameter('status', $status)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countByStatusSince(string $status, \DateTimeImmutable $since): int
    {
        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->where('r.status = :status')
            ->andWhere('r.moderatedAt >= :since')
            ->setParameter('status', $status)
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @return Report[] */
    public function findRecentByStatus(string $status, int $limit): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.status = :status')
            ->setParameter('status', $status)
            ->orderBy('r.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
