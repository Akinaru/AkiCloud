<?php

namespace App\Repository;

use App\Entity\Site;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Site>
 */
class SiteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Site::class);
    }

    //    /**
    //     * @return Site[] Returns an array of Site objects
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('s')
    //            ->andWhere('s.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('s.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    //    public function findOneBySomeField($value): ?Site
    //    {
    //        return $this->createQueryBuilder('s')
    //            ->andWhere('s.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }

    public function findWithPagination(int $page, int $limit, string $sort, string $direction): array
    {
        $queryBuilder = $this->createQueryBuilder('s')
            ->orderBy('s.' . $sort, $direction)
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit);

        return [
            'items' => $queryBuilder->getQuery()->getResult(),
            'total' => $this->count([]),
        ];
    }

    public function findWithPaginationForUser(User $user, int $page, int $limit, string $sort, string $direction): array
    {
        $queryBuilder = $this->createQueryBuilder('s')
            ->innerJoin('s.authorizedUsers', 'u')
            ->andWhere('u = :user')
            ->setParameter('user', $user)
            ->orderBy('s.' . $sort, $direction)
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit);

        $countQb = $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->innerJoin('s.authorizedUsers', 'u')
            ->andWhere('u = :user')
            ->setParameter('user', $user);

        return [
            'items' => $queryBuilder->getQuery()->getResult(),
            'total' => (int) $countQb->getQuery()->getSingleScalarResult(),
        ];
    }

    public function countAccessibleForUser(User $user): int
    {
        return (int) $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->innerJoin('s.authorizedUsers', 'u')
            ->andWhere('u = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return Site[]
     */
    public function findAccessibleForUser(User $user): array
    {
        return $this->createQueryBuilder('s')
            ->innerJoin('s.authorizedUsers', 'u')
            ->andWhere('u = :user')
            ->setParameter('user', $user)
            ->orderBy('s.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findOneByHost(string $host, string $baseDomain): ?Site
    {
        $normalizedHost = mb_strtolower(trim($host));
        $normalizedHost = preg_replace('/:\d+$/', '', $normalizedHost) ?? $normalizedHost;
        $normalizedBase = mb_strtolower(trim($baseDomain, '.'));

        $customDomainSite = $this->findOneBy(['customDomain' => $normalizedHost]);
        if ($customDomainSite instanceof Site) {
            return $customDomainSite;
        }

        if ($normalizedHost === '' || $normalizedBase === '') {
            return null;
        }

        if (!str_ends_with($normalizedHost, '.' . $normalizedBase)) {
            return null;
        }

        $subdomain = substr($normalizedHost, 0, -strlen('.' . $normalizedBase));
        if ($subdomain === '' || str_contains($subdomain, '.')) {
            return null;
        }

        return $this->findOneBy([
            'subdomain' => $subdomain,
            'customDomain' => null,
        ]);
    }
}
