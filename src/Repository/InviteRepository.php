<?php

namespace App\Repository;

use App\Entity\Invite;
use App\Entity\InviteStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Invite> */
class InviteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Invite::class);
    }

    public function findByTokenHash(string $tokenHash): ?Invite
    {
        return $this->findOneBy(['tokenHash' => $tokenHash]);
    }

    /**
     * Invites still waiting to be accepted: sent and not yet expired. Mirrors
     * Invite::isRedeemable().
     *
     * @return Invite[]
     */
    public function findPendingNewestFirst(): array
    {
        return $this->createQueryBuilder('i')
            ->where('i.status = :status')
            ->andWhere('i.expiresAt >= :now')
            ->setParameter('status', InviteStatus::Sent)
            ->setParameter('now', new \DateTimeImmutable())
            ->orderBy('i.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
