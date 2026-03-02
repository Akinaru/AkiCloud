<?php

namespace App\Repository;

use App\Entity\LoginAttempt;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LoginAttempt>
 */
class LoginAttemptRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LoginAttempt::class);
    }

    public function countLast24h(): int
    {
        $since = new \DateTimeImmutable('-24 hours');

        return (int) $this->createQueryBuilder('l')
            ->select('COUNT(l.id)')
            ->where('l.attemptedAt >= :since')
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countFailedLast24h(): int
    {
        $since = new \DateTimeImmutable('-24 hours');

        return (int) $this->createQueryBuilder('l')
            ->select('COUNT(l.id)')
            ->where('l.attemptedAt >= :since')
            ->andWhere('l.successful = false')
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return LoginAttempt[]
     */
    public function findRecent(int $limit = 20): array
    {
        return $this->createQueryBuilder('l')
            ->orderBy('l.attemptedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function findPaginated(int $page, int $limit, string $sort, string $direction): array
    {
        $queryBuilder = $this->createQueryBuilder('l')
            ->orderBy('l.' . $sort, $direction)
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit);

        return [
            'items' => $queryBuilder->getQuery()->getResult(),
            'total' => $this->count([]),
        ];
    }

    public function findLastSuccessfulAttemptForUser(string $userEmail): ?LoginAttempt
    {
        return $this->createQueryBuilder('l')
            ->where('l.email = :email')
            ->andWhere('l.successful = true')
            ->setParameter('email', $userEmail)
            ->orderBy('l.attemptedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
