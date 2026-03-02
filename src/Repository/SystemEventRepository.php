<?php

namespace App\Repository;

use App\Entity\SystemEvent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SystemEvent>
 */
class SystemEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SystemEvent::class);
    }

    public function findPaginated(int $page, int $limit, string $sort, string $direction): array
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
}
