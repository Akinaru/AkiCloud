<?php

namespace App\Repository;

use App\Entity\UserInvitation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserInvitation>
 */
class UserInvitationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserInvitation::class);
    }

    public function findValidByToken(string $token): ?UserInvitation
    {
        $invitation = $this->findOneBy(['token' => $token]);
        if (!$invitation) {
            return null;
        }

        if ($invitation->isAccepted() || $invitation->isExpired()) {
            return null;
        }

        return $invitation;
    }

    /** @return array<int, UserInvitation> */
    public function findPending(int $limit = 20): array
    {
        return $this->createQueryBuilder('i')
            ->andWhere('i.acceptedAt IS NULL')
            ->andWhere('i.expiresAt > :now')
            ->setParameter('now', new \DateTimeImmutable())
            ->orderBy('i.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}

