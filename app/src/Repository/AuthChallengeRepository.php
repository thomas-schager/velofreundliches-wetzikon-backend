<?php

namespace App\Repository;

use App\Entity\AuthChallenge;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AuthChallenge>
 */
class AuthChallengeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AuthChallenge::class);
    }

    public function findOneByChallengeToken(string $token): ?AuthChallenge
    {
        return $this->findOneBy(['challengeToken' => $token]);
    }

    public function findOneByResetToken(string $token): ?AuthChallenge
    {
        return $this->findOneBy(['resetToken' => $token]);
    }
}
