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
}
