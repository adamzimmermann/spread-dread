<?php

namespace App\Repository;

use App\Entity\Team;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Team> */
class TeamRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Team::class);
    }

    /** @return Team[] */
    public function findByYear(int $year): array
    {
        return $this->findBy(['year' => $year], ['region' => 'ASC', 'seed' => 'ASC']);
    }

    /** @return Team[] */
    public function findByYearAndRegion(int $year, string $region): array
    {
        return $this->findBy(['year' => $year, 'region' => $region], ['seed' => 'ASC']);
    }
}
