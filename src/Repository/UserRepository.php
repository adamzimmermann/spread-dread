<?php

namespace App\Repository;

use App\Entity\User;
use App\Entity\UserStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<User> */
class UserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    public function findByUsername(string $username): ?User
    {
        return $this->findOneBy(['username' => $username]);
    }

    public function findByEmail(string $email): ?User
    {
        return $this->findOneBy(['email' => strtolower(trim($email))]);
    }

    /**
     * Active users other than the given one, for opponent selection.
     *
     * @return User[]
     */
    public function findActiveOpponents(User $excluding): array
    {
        return $this->createQueryBuilder('u')
            ->where('u.status = :status')
            ->andWhere('u.id != :self')
            ->setParameter('status', UserStatus::Active->value)
            ->setParameter('self', $excluding->getId())
            ->orderBy('u.username', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return User[] */
    public function findAllForAdmin(): array
    {
        return $this->createQueryBuilder('u')
            ->orderBy('u.lastLoginAt', 'DESC')
            ->addOrderBy('u.username', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
