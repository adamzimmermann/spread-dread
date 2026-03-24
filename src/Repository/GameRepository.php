<?php

namespace App\Repository;

use App\Entity\Bracket;
use App\Entity\Game;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Game> */
class GameRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Game::class);
    }

    /** @return Game[] */
    public function findByBracketAndRound(Bracket $bracket, int $roundNumber): array
    {
        return $this->createQueryBuilder('g')
            ->where('g.bracket = :bracket')
            ->andWhere('g.roundNumber = :round')
            ->setParameter('bracket', $bracket)
            ->setParameter('round', $roundNumber)
            ->orderBy('g.region', 'ASC')
            ->addOrderBy('g.bracketPosition', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return Game[] */
    public function findByBracket(Bracket $bracket): array
    {
        return $this->createQueryBuilder('g')
            ->where('g.bracket = :bracket')
            ->setParameter('bracket', $bracket)
            ->orderBy('g.roundNumber', 'ASC')
            ->addOrderBy('g.region', 'ASC')
            ->addOrderBy('g.bracketPosition', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
