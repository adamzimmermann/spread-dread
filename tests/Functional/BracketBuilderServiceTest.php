<?php

namespace App\Tests\Functional;

use App\Entity\Game;
use App\Service\BracketBuilderService;

class BracketBuilderServiceTest extends WebTestCase
{
    public function testBuildBracketCreates63Games(): void
    {
        $user1 = $this->createUser('bb_63_p1');
        $user2 = $this->createUser('bb_63_p2');
        $bracket = $this->createBracket($user1, $user2);

        $builder = static::getContainer()->get(BracketBuilderService::class);
        $builder->buildBracket($bracket);

        $games = $this->em->getRepository(Game::class)->findBy(['bracket' => $bracket]);
        $this->assertCount(63, $games);
    }

    public function testBuildBracketRoundCounts(): void
    {
        $user1 = $this->createUser('bb_rounds_p1');
        $user2 = $this->createUser('bb_rounds_p2');
        $bracket = $this->createBracket($user1, $user2);

        $builder = static::getContainer()->get(BracketBuilderService::class);
        $builder->buildBracket($bracket);

        $games = $this->em->getRepository(Game::class)->findBy(['bracket' => $bracket]);

        $roundCounts = [];
        foreach ($games as $game) {
            $round = $game->getRoundNumber();
            $roundCounts[$round] = ($roundCounts[$round] ?? 0) + 1;
        }

        $this->assertSame(32, $roundCounts[1]); // Round of 64
        $this->assertSame(16, $roundCounts[2]); // Round of 32
        $this->assertSame(8, $roundCounts[3]);  // Sweet 16
        $this->assertSame(4, $roundCounts[4]);  // Elite 8
        $this->assertSame(2, $roundCounts[5]);  // Final Four
        $this->assertSame(1, $roundCounts[6]);  // Championship
    }

    public function testBuildBracketNextGameWiring(): void
    {
        $user1 = $this->createUser('bb_wiring_p1');
        $user2 = $this->createUser('bb_wiring_p2');
        $bracket = $this->createBracket($user1, $user2);

        $builder = static::getContainer()->get(BracketBuilderService::class);
        $builder->buildBracket($bracket);

        $games = $this->em->getRepository(Game::class)->findBy(['bracket' => $bracket]);

        $gamesWithoutNext = 0;
        $gamesWithNext = 0;
        foreach ($games as $game) {
            if ($game->getNextGame() === null) {
                $gamesWithoutNext++;
                $this->assertSame(6, $game->getRoundNumber(), 'Only championship should have no nextGame');
            } else {
                $gamesWithNext++;
                $this->assertGreaterThan(
                    $game->getRoundNumber(),
                    $game->getNextGame()->getRoundNumber(),
                    'nextGame should be in a later round'
                );
            }
        }

        $this->assertSame(1, $gamesWithoutNext);
        $this->assertSame(62, $gamesWithNext);
    }

    public function testBuildBracketRegions(): void
    {
        $user1 = $this->createUser('bb_regions_p1');
        $user2 = $this->createUser('bb_regions_p2');
        $bracket = $this->createBracket($user1, $user2);

        $builder = static::getContainer()->get(BracketBuilderService::class);
        $builder->buildBracket($bracket);

        $games = $this->em->getRepository(Game::class)->findBy(['bracket' => $bracket]);

        $regionCounts = [];
        foreach ($games as $game) {
            $region = $game->getRegion() ?? 'National';
            $regionCounts[$region] = ($regionCounts[$region] ?? 0) + 1;
        }

        foreach (['East', 'West', 'South', 'Midwest'] as $region) {
            $this->assertSame(15, $regionCounts[$region], "Region $region should have 15 games");
        }
        $this->assertSame(3, $regionCounts['National']);
    }

    public function testEachFinalFourGameIsFedByPositionsOneAndTwo(): void
    {
        $bracket = $this->createBracket($this->createUser('bb_e8pos_p1'), $this->createUser('bb_e8pos_p2'));
        static::getContainer()->get(BracketBuilderService::class)->buildBracket($bracket);

        $feeders = [];
        foreach ($this->em->getRepository(Game::class)->findBy(['bracket' => $bracket, 'roundNumber' => 4]) as $e8) {
            $feeders[$e8->getNextGame()->getBracketPosition()][] = $e8->getBracketPosition();
        }

        foreach ($feeders as $ffPosition => $positions) {
            sort($positions);
            $this->assertSame([1, 2], $positions, "Final Four game $ffPosition");
        }
    }

    public function testBothRegionalChampionsReachTheFinalFour(): void
    {
        $bracket = $this->createBracket($this->createUser('bb_e8adv_p1'), $this->createUser('bb_e8adv_p2'));
        static::getContainer()->get(BracketBuilderService::class)->buildBracket($bracket);
        $scoring = static::getContainer()->get(\App\Service\ScoringService::class);

        foreach ($this->em->getRepository(Game::class)->findBy(['bracket' => $bracket, 'roundNumber' => 4]) as $e8) {
            $champ = $this->createTeam($e8->getRegion() . ' Champ', 1, $e8->getRegion());
            $e8->setTeam1($champ)->setTeam2($this->createTeam($e8->getRegion() . ' Runner', 2, $e8->getRegion()));
            $e8->setTeam1Score(70)->setTeam2Score(60)->setWinner($champ)->setIsComplete(true);
            $this->em->flush();
            $scoring->advanceWinner($e8);
        }

        foreach ($this->em->getRepository(Game::class)->findBy(['bracket' => $bracket, 'roundNumber' => 5]) as $ff) {
            $this->assertNotNull($ff->getTeam1(), 'Final Four team1');
            $this->assertNotNull($ff->getTeam2(), 'Final Four team2');
        }
    }

    public function testFinalFourFollowsTheGivenPairing(): void
    {
        $bracket = $this->createBracket($this->createUser('bb_pair_p1'), $this->createUser('bb_pair_p2'));
        static::getContainer()->get(BracketBuilderService::class)
            ->buildBracket($bracket, [['East', 'South'], ['West', 'Midwest']]);

        $byFinalFour = [];
        foreach ($this->em->getRepository(Game::class)->findBy(['bracket' => $bracket, 'roundNumber' => 4]) as $e8) {
            $byFinalFour[$e8->getNextGame()->getBracketPosition()][$e8->getBracketPosition()] = $e8->getRegion();
        }

        $this->assertSame([1 => 'East', 2 => 'South'], $byFinalFour[1]);
        $this->assertSame([1 => 'West', 2 => 'Midwest'], $byFinalFour[2]);
    }
}
