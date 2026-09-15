<?php

namespace App\Repository;

use App\Entity\PasswordResetToken;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<PasswordResetToken> */
class PasswordResetTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PasswordResetToken::class);
    }

    public function findByTokenHash(string $tokenHash): ?PasswordResetToken
    {
        return $this->findOneBy(['tokenHash' => $tokenHash]);
    }

    /** Marks every unused token for this user as used. */
    public function invalidateAllForUser(User $user, \DateTimeImmutable $at): void
    {
        $this->createQueryBuilder('t')
            ->update()
            ->set('t.usedAt', ':at')
            ->where('t.user = :user')
            ->andWhere('t.usedAt IS NULL')
            ->setParameter('at', $at)
            ->setParameter('user', $user)
            ->getQuery()
            ->execute();
    }
}
