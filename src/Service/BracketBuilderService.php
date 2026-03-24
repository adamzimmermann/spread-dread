<?php

namespace App\Service;

use App\Entity\Bracket;
use App\Entity\Game;
use App\Entity\Round;
use App\Entity\Team;
use App\Repository\TeamRepository;
use Doctrine\ORM\EntityManagerInterface;

class BracketBuilderService
{
    // Standard NCAA bracket matchup order by seed (1v16, 8v9, 5v12, 4v13, 6v11, 3v14, 7v10, 2v15)
    private const SEED_MATCHUPS = [
        [1, 16], [8, 9], [5, 12], [4, 13],
        [6, 11], [3, 14], [7, 10], [2, 15],
    ];

    private const REGIONS = ['East', 'West', 'South', 'Midwest'];

    public function __construct(
        private EntityManagerInterface $em,
        private TeamRepository $teamRepository,
    ) {
    }

    public function buildBracket(Bracket $bracket): void
    {
        $year = $bracket->getYear();
        $teams = $this->teamRepository->findByYear($year);
        $teamsByRegion = [];
        foreach ($teams as $team) {
            $teamsByRegion[$team->getRegion()][$team->getSeed()] = $team;
        }

        // Create all 63 games and wire nextGame references
        // Structure: 32 R64 + 16 R32 + 8 S16 + 4 E8 + 2 F4 + 1 Championship

        $allGames = [];

        // Build from championship backwards so we can set nextGame references
        // Round 6: Championship (1 game, no region)
        $championship = $this->createGame($bracket, 6, null, 1);
        $allGames[6] = [$championship];

        // Round 5: Final Four (2 games, no region)
        $ff1 = $this->createGame($bracket, 5, null, 1);
        $ff1->setNextGame($championship);
        $ff2 = $this->createGame($bracket, 5, null, 2);
        $ff2->setNextGame($championship);
        $allGames[5] = [$ff1, $ff2];

        // Rounds 1-4: Regional rounds
        // Each region feeds one team to Final Four
        // Regions pair: East/West -> FF game 1, South/Midwest -> FF game 2
        $regionPairs = [
            [self::REGIONS[0], self::REGIONS[1]], // East, West -> FF1
            [self::REGIONS[2], self::REGIONS[3]], // South, Midwest -> FF2
        ];

        foreach ([4, 3, 2, 1] as $round) {
            $allGames[$round] = [];
        }

        foreach ($regionPairs as $pairIndex => $regions) {
            $ffGame = $allGames[5][$pairIndex];

            foreach ($regions as $regionIndex => $region) {
                // Elite 8: 1 game per region -> feeds to Final Four
                $e8 = $this->createGame($bracket, 4, $region, 1);
                $e8->setNextGame($ffGame);
                $allGames[4][] = $e8;

                // Sweet 16: 2 games per region -> feed to E8
                $s16Games = [];
                for ($pos = 1; $pos <= 2; $pos++) {
                    $s16 = $this->createGame($bracket, 3, $region, $pos);
                    $s16->setNextGame($e8);
                    $s16Games[] = $s16;
                    $allGames[3][] = $s16;
                }

                // Round of 32: 4 games per region -> feed to S16
                $r32Games = [];
                for ($pos = 1; $pos <= 4; $pos++) {
                    $nextS16 = $s16Games[intdiv($pos - 1, 2)];
                    $r32 = $this->createGame($bracket, 2, $region, $pos);
                    $r32->setNextGame($nextS16);
                    $r32Games[] = $r32;
                    $allGames[2][] = $r32;
                }

                // Round of 64: 8 games per region -> feed to R32
                for ($pos = 1; $pos <= 8; $pos++) {
                    $nextR32 = $r32Games[intdiv($pos - 1, 2)];
                    $r64 = $this->createGame($bracket, 1, $region, $pos);
                    $r64->setNextGame($nextR32);

                    // Assign teams if available
                    $matchup = self::SEED_MATCHUPS[$pos - 1];
                    if (isset($teamsByRegion[$region][$matchup[0]])) {
                        $r64->setTeam1($teamsByRegion[$region][$matchup[0]]);
                    }
                    if (isset($teamsByRegion[$region][$matchup[1]])) {
                        $r64->setTeam2($teamsByRegion[$region][$matchup[1]]);
                    }

                    $allGames[1][] = $r64;
                }
            }
        }

        // Create Round entities
        for ($r = 1; $r <= 6; $r++) {
            $round = new Round();
            $round->setYear($year);
            $round->setRoundNumber($r);
            $round->setName(Round::getRoundName($r));
            $this->em->persist($round);
        }

        $this->em->flush();
    }

    private function createGame(Bracket $bracket, int $roundNumber, ?string $region, int $position): Game
    {
        $game = new Game();
        $game->setRoundNumber($roundNumber);
        $game->setRegion($region);
        $game->setBracketPosition($position);
        $bracket->addGame($game);
        $this->em->persist($game);
        return $game;
    }
}
